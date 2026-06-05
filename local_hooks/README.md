# Local Development Hooks

This folder contains local hooks for customizing test behavior and local-only notifications on development servers.

## Purpose

These hooks allow you to override default data storage locations by creating symlinks to external directories (e.g., on SSD storage). This is useful when the dev server has limited space in the project directory but has more space available elsewhere.

They also provide local-only notification hooks for nightly benchmark runs. The tracked nightly scripts only source hook files and pass context; webhook URLs, Slack payload formatting, and other team-specific notification details should stay in local hook files.

## Available Hooks

- `hn_hook.sh`: Hook for the "hn" test suite. Symlinks `manticoresearch/idx$suffix` to `/mnt/ssd/hn_manticore_data_nightly/`
- `taxi_hook.sh`: Hook for the "taxi" test suite. Symlinks `manticoresearch/idx$suffix` to `/mnt/ssd/taxi_manticore_data_nightly/`
- `nightly_hook.sh`: Hook sourced by `nightly_manticore.sh` after successful nightly results are saved. Use it for successful result notifications only.
- `nightly_failure_hook.sh`: Hook sourced by `run_nightly.sh` when a nightly run clearly fails or is skipped for an operational reason. Use it for failed/skipped notifications only.

## How It Works

### Test data hooks

1. The `pre_hook` scripts in each test's manticoresearch directory check for the existence of the corresponding hook file.
2. If the hook file exists, it is sourced (executed) before the normal initialization process.
3. The hook removes any existing data in the target directory and creates a symlink from the project's `manticoresearch/idx$suffix` to the external storage location.

### Nightly notification hooks

1. `nightly_manticore.sh` sources `local_hooks/nightly_hook.sh` only after results are saved successfully.
2. `run_nightly.sh` sources `local_hooks/nightly_failure_hook.sh` only when a run exits as failed or skipped.
3. If a notification hook file does not exist, the notification is silently skipped.
4. Existing-result skips are not treated as failures and should not notify the team.

## Usage

### Test data hooks

1. Ensure the external storage directory exists (e.g., `/mnt/ssd/hn_manticore_data_nightly/`).
2. Create or copy the appropriate hook file in this directory.
3. Run the test initialization as usual - the hook will be applied automatically.

### Nightly notification hooks

Create local notification hook files as needed:

- `local_hooks/nightly_hook.sh` for successful result notifications.
- `local_hooks/nightly_failure_hook.sh` for failed/skipped notifications.

`nightly_failure_hook.sh` receives these environment variables from `run_nightly.sh`:

- `NIGHTLY_FAILURE_STATUS`: `failed` or `skipped`
- `NIGHTLY_FAILURE_TAG`: nightly image tag, e.g. `latest` or `dev`
- `NIGHTLY_FAILURE_EXIT_CODE`: exit code from `nightly_manticore.sh`
- `NIGHTLY_FAILURE_LOG`: path to the failed/skipped run log

Keep webhook URLs and other credentials inside the local hook files only.

## Important Notes

- These hooks are intended for local development only and should not be committed to the repository.
- The `local_hooks/` folder is ignored by git (added to `.gitignore`), except for this README.
- Adjust the paths in the hook files if your external storage location differs.
- Ensure proper permissions on the external storage directory.
- Keep successful result notifications and failed/skipped notifications in separate hook files.
- Do not put webhook URLs, Slack payloads, or credentials in tracked nightly scripts.