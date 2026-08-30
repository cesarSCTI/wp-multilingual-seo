# MLS integration suite

This suite starts disposable WordPress, MariaDB and Elementor 3.32.5 containers. It creates an Elementor page and an English translation without calling Google, then verifies routing, render preservation, locale logging, subdirectory paths, cache isolation and the real public HTML response.

## Windows / Docker Desktop

```powershell
powershell.exe -NoProfile -ExecutionPolicy Bypass -File tests\Integration\run.ps1
```

The runner removes only its `integration_*` containers, network and named volume when it finishes.

## Linux with Docker Compose

```sh
sh tests/Integration/run.sh
```

The shell runner executes the WordPress-side assertions. The PowerShell runner additionally performs real HTTP response assertions.
