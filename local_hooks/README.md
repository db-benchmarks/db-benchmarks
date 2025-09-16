# Local Development Hooks

This folder contains local hooks for customizing test behavior on development servers, particularly for handling space constraints.

## Purpose

These hooks allow you to override default data storage locations by creating symlinks to external directories (e.g., on SSD storage). This is useful when the dev server has limited space in the project directory but has more space available elsewhere.

## Available Hooks

- `hn_hook.sh`: Hook for the "hn" test suite. Symlinks `manticoresearch/idx$suffix` to `/mnt/ssd/hn_manticore_data_nightly/`
- `taxi_hook.sh`: Hook for the "taxi" test suite. Symlinks `manticoresearch/idx$suffix` to `/mnt/ssd/taxi_manticore_data_nightly/`

## How It Works

1. The `pre_hook` scripts in each test's manticoresearch directory check for the existence of the corresponding hook file.
2. If the hook file exists, it is sourced (executed) before the normal initialization process.
3. The hook removes any existing data in the target directory and creates a symlink from the project's `manticoresearch/idx$suffix` to the external storage location.

## Usage

1. Ensure the external storage directory exists (e.g., `/mnt/ssd/hn_manticore_data_nightly/`).
2. Create or copy the appropriate hook file in this directory.
3. Run the test initialization as usual - the hook will be applied automatically.

## Important Notes

- These hooks are intended for local development only and should not be committed to the repository.
- The `local_hooks/` folder is ignored by git (added to `.gitignore`).
- Adjust the paths in the hook files if your external storage location differs.
- Ensure proper permissions on the external storage directory.