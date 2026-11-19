#!/bin/sh
# The desktop-sync container (docs/docker-deployment.md, step 13): keeps the desktop-releases volume equal to the
# latest published release of a GitHub repository, so that no one copies installers to the server by hand.
#
# Every DESKTOP_SYNC_INTERVAL seconds it reads the repository's latest release (drafts and pre-releases are never
# taken). When its tag is new, it downloads every file of the release: the installers and .blockmap files first,
# each under a hidden name and renamed once complete (with its size checked), then the *.yml manifests last.
# A client therefore never reads a manifest naming an installer that is not there yet. Files of older releases
# are deleted DESKTOP_KEEP_DAYS days after they stopped being current.
#
#   DESKTOP_RELEASES_REPO    owner/name of the repository whose releases are published (empty: the container idles)
#   DESKTOP_RELEASES_TOKEN   a GitHub token that can read it (needed when the repository is private)
#   DESKTOP_SYNC_INTERVAL    seconds between two looks (default 300)
#   DESKTOP_KEEP_DAYS        days the files of older releases are kept (default 30)
#   GITHUB_API               the API's address (default https://api.github.com)
set -u

REPO="${DESKTOP_RELEASES_REPO:-}"
TOKEN="${DESKTOP_RELEASES_TOKEN:-}"
INTERVAL="${DESKTOP_SYNC_INTERVAL:-300}"
KEEP_DAYS="${DESKTOP_KEEP_DAYS:-30}"
API="${GITHUB_API:-https://api.github.com}"
DIR="${DESKTOP_DIR:-/srv/desktop}"
TAB="$(printf '\t')"

log() { echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) desktop-sync: $*"; }

# github <url> [curl options…]: a GitHub API call, with the token when there is one. On a redirect to another
# host (an asset's download), curl does not send the Authorization header on.
github() {
    url="$1"; shift
    if [ -n "$TOKEN" ]; then
        curl --fail --silent --show-error --location --retry 3 --max-time 1800 \
             -H "Authorization: Bearer $TOKEN" -H "X-GitHub-Api-Version: 2022-11-28" "$@" "$url"
    else
        curl --fail --silent --show-error --location --retry 3 --max-time 1800 \
             -H "X-GitHub-Api-Version: 2022-11-28" "$@" "$url"
    fi
}

# fetch <name> <api-url> <size>: one file of the release into DIR, complete or not at all.
fetch() {
    name="$1"; url="$2"; size="$3"
    # An installer of the same name and size is already there (its name carries the version). A manifest is
    # always downloaded again: every release has a latest.yml, often of the same size.
    case "$name" in
        *.yml) ;;
        *) if [ -f "$DIR/$name" ] && [ "$(stat -c %s "$DIR/$name")" = "$size" ]; then return 0; fi ;;
    esac
    part="$DIR/.$name.part"
    if ! github "$url" -H "Accept: application/octet-stream" -o "$part"; then
        rm -f "$part"; log "could not download $name"; return 1
    fi
    got="$(stat -c %s "$part")"
    if [ "$got" != "$size" ]; then
        rm -f "$part"; log "$name: $got bytes downloaded, $size expected"; return 1
    fi
    chmod 644 "$part" && mv -f "$part" "$DIR/$name" && log "$name ($size bytes)"
}

sync_once() {
    release="$(github "$API/repos/$REPO/releases/latest" -H "Accept: application/vnd.github+json")" || {
        log "cannot read the latest release of $REPO (the name, or the token's access?)"; return 1; }

    tag="$(printf '%s' "$release" | jq -r '.tag_name // empty' | tr -d '\r')"
    [ -n "$tag" ] || { log "the latest release of $REPO has no tag"; return 1; }
    [ "$(cat "$DIR/.synced-tag" 2>/dev/null)" = "$tag" ] && return 0

    # name <TAB> url <TAB> size: the installers and blockmaps, then the manifests.
    files="$(printf '%s' "$release" | jq -r '
        [.assets[] | select(.name | endswith(".yml") | not)] + [.assets[] | select(.name | endswith(".yml"))]
        | .[] | [.name, .url, (.size | tostring)] | @tsv' | tr -d '\r')"
    printf '%s\n' "$files" | grep -q '\.yml'"$TAB" || {
        log "release $tag of $REPO has no latest.yml: not published"; return 1; }

    log "release $tag of $REPO: downloading"
    printf '%s\n' "$files" | while IFS="$TAB" read -r name url size; do
        case "$name" in */* | .* | '') log "skipping the file name «$name»"; continue ;; esac
        fetch "$name" "$url" "$size" || exit 1
    done || return 1

    printf '%s\n' "$tag" > "$DIR/.synced-tag.part" && mv -f "$DIR/.synced-tag.part" "$DIR/.synced-tag"
    printf '%s\n' "$files" | cut -f1 > "$DIR/.current-files"
    log "release $tag is served"
}

# The files of older releases, once they are DESKTOP_KEEP_DAYS days old (a client may still be downloading one).
prune() {
    [ -f "$DIR/.current-files" ] || return 0
    find "$DIR" -maxdepth 1 -type f ! -name '.*' -mtime +"$KEEP_DAYS" | while read -r path; do
        grep -Fxq "$(basename "$path")" "$DIR/.current-files" || { rm -f "$path" && log "deleted $(basename "$path")"; }
    done
}

mkdir -p "$DIR"
rm -f "$DIR"/.*.part 2>/dev/null

if [ -z "$REPO" ]; then
    log "DESKTOP_RELEASES_REPO is empty: nothing to publish. Set it to owner/name, then deploy again."
    while :; do sleep 3600; done
fi

log "publishing the latest release of $REPO, looking every ${INTERVAL}s"
while :; do
    sync_once && prune
    [ "${DESKTOP_SYNC_ONCE:-}" = "1" ] && exit 0
    sleep "$INTERVAL"
done
