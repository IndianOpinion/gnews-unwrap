# Security policy

## Supported versions

| Version | Supported |
|---|---|
| 0.1.x | yes |

## Reporting a vulnerability

Email **hello@indianopinion.org** with "gnews-unwrap security" in the subject.
Please do not open a public issue.

Include the version, a description, and a proof of concept if you have one
(a URL or a recorded response is ideal).

We acknowledge reports within 3 working days and aim to ship a fix or a
mitigation within 30 days, sooner for anything that lets crafted input reach
a non-Google host, exhaust memory, or return a wrong publisher URL as
resolved. We will credit you in the release notes unless you ask us not to.

## Scope notes

- In the default mode the library only sends requests to Google hosts. A way
  to make it request any other host is a vulnerability.
- Generic redirect mode follows redirects to any host. It refuses private and
  metadata addresses, but see the DNS limitation in the README.
- The batchexecute method uses an undocumented Google endpoint. Google
  changing it is a bug, not a security issue.
