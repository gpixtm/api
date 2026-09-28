import 'dart:convert';
import 'dart:io';

Future<void> main(List<String> args) async {
  final root = File.fromUri(Platform.script).parent.parent;
  final mobile = Directory('${root.parent.path}/mobile');
  if (!mobile.existsSync()) {
    stderr.writeln(
      'Mobile Dev commands require the complete workspace, with mobile/ and .tooling/ alongside backend/. For the standalone server: make install or make up.',
    );
    exitCode = 1;
    return;
  }
  for (final name in ['dev', 'prod']) {
    final local = File('${mobile.path}/config/$name.local.json');
    if (!await local.exists()) {
      await File('${mobile.path}/config/$name.example.json').copy(local.path);
    }
  }
  if (args.singleOrNull == 'init') {
    stdout.writeln(
      'Local configuration ready. Set API_URL in mobile/config/dev.local.json and prod.local.json. Existing files were preserved.',
    );
    return;
  }
  try {
    final json = jsonDecode(
      await File('${mobile.path}/config/dev.local.json').readAsString(),
    ) as Map<String, dynamic>;
    final url = (json['API_URL'] as String? ?? '').trim();
    final uri = Uri.tryParse(url);
    if (uri == null ||
        uri.scheme != 'http' ||
        !isPrivateIpv4(uri.host) ||
        uri.userInfo.isNotEmpty ||
        uri.hasQuery ||
        uri.hasFragment ||
        uri.port < 1 ||
        uri.port > 65535 ||
        (uri.path.isNotEmpty && uri.path != '/')) {
      throw StateError(
        'make dev requires http://COMPUTER_LAN_IP:PORT without a path. HTTPS belongs to your reverse proxy.',
      );
    }
    final dockerArgs = [
      'compose',
      '-f',
      'compose.yaml',
      '-f',
      'compose.mailpit.yaml',
      '-f',
      'compose.dev.yaml',
    ];
    if (args.singleOrNull == 'config') {
      dockerArgs.addAll(['config', '--format', 'json']);
      final result = await Process.run(
        'docker',
        dockerArgs,
        workingDirectory: root.path,
        environment: {'DEV_BIND_IP': uri.host, 'DEV_HTTP_PORT': '${uri.port}'},
      );
      if (result.exitCode != 0) {
        stderr.write(result.stderr);
        exitCode = result.exitCode;
        return;
      }
      final resolved = jsonDecode(result.stdout as String);
      // Print only ports; never print resolved environment secrets.
      stdout.writeln(jsonEncode(resolved['services']['api']['ports']));
      return;
    }
    dockerArgs.addAll(['up', '-d', '--wait']);
    stdout.writeln('Dev API at $url (LAN interface only).');
    final process = await Process.start(
      'docker',
      dockerArgs,
      workingDirectory: root.path,
      environment: {'DEV_BIND_IP': uri.host, 'DEV_HTTP_PORT': '${uri.port}'},
      mode: ProcessStartMode.inheritStdio,
    );
    exitCode = await process.exitCode;
    if (exitCode != 0) return;
    final client = HttpClient()..connectionTimeout = const Duration(seconds: 5);
    client.findProxy = (_) => 'DIRECT';
    try {
      final request = await client.getUrl(uri.resolve('/health'));
      request.followRedirects = false;
      final response = await request.close().timeout(
        const Duration(seconds: 5),
      );
      if (response.statusCode != 200) {
        throw HttpException('Health HTTP ${response.statusCode}');
      }
      await response.drain<void>().timeout(const Duration(seconds: 5));
      stdout.writeln(
        'API reachable from the computer. Also check /health from the phone.',
      );
    } catch (_) {
      stderr.writeln(
        'Docker is running, but $url/health is unreachable from the computer. '
        'This check is advisory: Docker Desktop can restrict this access while the phone reaches the server. '
        'Check /health from the phone. make dev-off restores loopback.',
      );
      // A Windows host may not reach its own published LAN interface while
      // another device can. Compose health succeeded; this is advisory only.
    } finally {
      client.close(force: true);
    }
  } catch (e) {
    stderr.writeln(e);
    exitCode = 1;
  }
}

// CLI publishing policy: bind only an explicit private IPv4 interface.
bool isPrivateIpv4(String host) {
  final parts = host.split('.');
  if (parts.length != 4) return false;
  final bytes = parts.map(int.tryParse).toList();
  if (bytes.any((v) => v == null || v < 0 || v > 255)) return false;
  if (bytes.join('.') != host) return false;
  return bytes[0] == 10 ||
      (bytes[0] == 192 && bytes[1] == 168) ||
      (bytes[0] == 172 && bytes[1]! >= 16 && bytes[1]! <= 31);
}
