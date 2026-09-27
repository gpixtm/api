#!/bin/sh
# Run as the production Unix user. A short-lived GitHub registration token is read from stdin.
set -eu
umask 077
runner_dir="$HOME/actions-runner-gpix"
mkdir -p "$runner_dir"
cd "$runner_dir"
if [ -f .runner ]; then echo 'Runner is already registered.' >&2; exit 1; fi
version=2.337.0
archive="actions-runner-linux-x64-$version.tar.gz"
curl -fL --retry 3 -o "$archive" "https://github.com/actions/runner/releases/download/v$version/$archive"
printf '%s  %s\n' '70920811a4f8ad4328818682bca5c6469c1c942fab52448868071d0063816613' "$archive" | sha256sum -c -
tar -xzf "$archive"
rm "$archive"
IFS= read -r token
token=$(printf '%s' "$token" | tr -d '\r\n')
./config.sh --unattended --url https://github.com/gpixtm/api --token "$token" --name gpix-production --labels gpix-production --work _work
unset token
mkdir -p "$HOME/.config/systemd/user"
cat > "$HOME/.config/systemd/user/gpix-actions-runner.service" <<EOF
[Unit]
Description=GitHub Actions runner for gpixtm/api
After=network-online.target

[Service]
WorkingDirectory=$runner_dir
ExecStart=$runner_dir/run.sh
Restart=always
RestartSec=10
KillSignal=SIGINT
TimeoutStopSec=300
UMask=0077

[Install]
WantedBy=default.target
EOF
systemctl --user daemon-reload
systemctl --user enable --now gpix-actions-runner.service
systemctl --user is-active gpix-actions-runner.service
loginctl show-user "$(id -un)" -p Linger
