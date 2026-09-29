#!/bin/sh
# Registers a self-hosted GitHub Actions runner for deployments, run as the
# production Unix user. A short-lived registration token is read from stdin.
# RUNNER_REPOSITORY_URL is the repository running the deployment workflow; keep
# self-hosted runners away from public repositories, whose forks can run code.
set -eu
umask 077
repository_url=${RUNNER_REPOSITORY_URL:?Set RUNNER_REPOSITORY_URL, for example https://github.com/owner/deploy-repo}
runner_name=${RUNNER_NAME:-gpix-production}
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
./config.sh --unattended --url "$repository_url" --token "$token" --name "$runner_name" --labels "$runner_name" --work _work
unset token
mkdir -p "$HOME/.config/systemd/user"
cat > "$HOME/.config/systemd/user/gpix-actions-runner.service" <<EOF
[Unit]
Description=GitHub Actions runner for Gpix deployments
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
