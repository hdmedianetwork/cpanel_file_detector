# Security Policy

Storage Guard runs as root on production hosting servers, so we take its security seriously.

## Supported versions

Only the latest release receives fixes. Update from **WHM → Storage Guard → Check for Updates**.

| Version | Supported |
|---|---|
| latest (see [VERSION](VERSION)) | ✅ |
| older | ❌ |

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Report it privately through GitHub: open the **Security** tab of this repository and choose
**Report a vulnerability**. Include:

- the version (`cat /opt/skyserver-storage-guard/VERSION`)
- what an attacker can do, and from where (a cPanel account, a WHM reseller, the network)
- steps to reproduce

We aim to acknowledge reports within 3 working days and to ship a fix for confirmed issues as
quickly as their severity requires.

## Security model

What Storage Guard defends against:

| Threat | Mitigation |
|---|---|
| A customer tricks root into touching a file outside their account | Paths are walked one level at a time, refusing anything that isn't a real directory, and each step is pinned by device and inode. Symlinks are never followed. |
| A crafted request names an arbitrary path | The dashboard only accepts IDs of files found by the latest scan. |
| A customer abuses the root-written notice in their home | The folder is taken over by root before anything is written into it. Files are created exclusively and renamed into place. |
| A customer reads another customer's data | Notices are `0640 root:<user>` in a directory that can't be listed. The customer page runs as the account itself. |
| A customer triggers scans of other accounts | Rescan requests are honoured only when the request file's owner is the account it names. |
| Cross-site request forgery | Every change is POST-only, behind WHM's and cPanel's session tokens. |
| A tampered or broken update | The updater runs only a file that is a valid Storage Guard installer, and replaces the code as a whole. |

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full layout and permissions.
