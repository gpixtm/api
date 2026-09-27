<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

$recipient = $argv[1] ?? '';
if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Usage: php bin/mail-test.php recipient@example.com\n");
    exit(1);
}
if (!App\Infrastructure\ResetDelivery::configured()) {
    fwrite(STDERR, "Configure MAILER_DSN and MAIL_FROM in the private production .env, then run make prod-reload.\n");
    exit(1);
}
try {
    $mailer = new Symfony\Component\Mailer\Mailer(
        Symfony\Component\Mailer\Transport::fromDsn(getenv('MAILER_DSN'))
    );
    $mailer->send((new Symfony\Component\Mime\Email())
        ->from(getenv('MAIL_FROM'))
        ->to($recipient)
        ->subject('Gpix — test SMTP')
        ->text("L'envoi des emails Gpix fonctionne depuis le serveur de production.\n"));
    echo "SMTP accepted the message. Check the recipient's inbox and spam folder to confirm delivery.\n";
} catch (Throwable $e) {
    // Do not print SMTP diagnostics: they may contain authentication material.
    fwrite(STDERR, "SMTP failed. Check the host, port, app password and allowed sender. No secret was logged.\n");
    exit(1);
}
