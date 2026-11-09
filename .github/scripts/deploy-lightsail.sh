#!/usr/bin/env bash
# GitHub runner only. Never invoke locally with production credentials for testing.
set -Eeuo pipefail
umask 077
readonly host=63.184.35.171
readonly login=ubuntu
fail() { printf '%s\n' "Deployment refused: $*" >&2; exit 1; }
[[ ${DEPLOY_ENABLED:-} == true ]] || fail 'disabled by repository variable.'
[[ ${VERIFY_RESULT:-} == success && ${GITHUB_EVENT_NAME:-} == push && ${GITHUB_REF:-} == refs/heads/main && ${GITHUB_REPOSITORY:-} == BashukOleksii/Student-AI-Planner-Backend ]] || fail 'only verified main pushes may deploy.'
[[ ${GITHUB_SHA:-} =~ ^[0-9a-f]{40}$ ]] || fail 'invalid tested SHA.'
[[ -n ${LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY:-} && -n ${LIGHTSAIL_BACKEND_KNOWN_HOSTS:-} ]] || fail 'missing production SSH credentials or pinned known_hosts.'
work=$(mktemp -d)
cleanup() { rm -rf "$work"; }
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' HUP TERM
printf '%s\n' "$LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY" > "$work/key"
printf '%s\n' "$LIGHTSAIL_BACKEND_KNOWN_HOSTS" > "$work/known_hosts"
unset LIGHTSAIL_BACKEND_SSH_PRIVATE_KEY LIGHTSAIL_BACKEND_KNOWN_HOSTS
ssh-keygen -y -P '' -f "$work/key" > /dev/null 2>&1 || fail 'invalid or encrypted deployment key.'
ssh-keygen -F "$host" -f "$work/known_hosts" > /dev/null 2>&1 || fail 'no pinned host entry for deployment address.'
# No agent, forwarding, ambient SSH config, TOFU, response bodies or cookies in output.
ssh -F /dev/null -T -i "$work/key" \
    -o IdentitiesOnly=yes -o IdentityAgent=none -o ForwardAgent=no \
    -o ClearAllForwardings=yes -o BatchMode=yes -o PasswordAuthentication=no \
    -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$work/known_hosts" \
    -o GlobalKnownHostsFile=/dev/null -o ConnectTimeout=10 \
    -o ServerAliveInterval=15 -o ServerAliveCountMax=3 \
    "$login@$host" "/usr/local/libexec/student-planner-backend-deploy $GITHUB_SHA"
