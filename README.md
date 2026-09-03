# wp_custom_gpt

WordPress plugin scaffold for porting core functionality from pwa_custom_gpt.

## Current implementation status

- Plugin bootstrap and activation hook
- Database migration with core tables:
  - `wpcgpt_rooms`
  - `wpcgpt_chats`
  - `wpcgpt_messages`
  - `wpcgpt_flow_sessions`
- REST API v1 endpoints:
  - `GET /wp-json/wp-custom-gpt/v1/health`
  - `GET /wp-json/wp-custom-gpt/v1/settings` (admin only)
  - `POST /wp-json/wp-custom-gpt/v1/settings` (admin only)
  - `GET /wp-json/wp-custom-gpt/v1/rooms` (logged-in users)
  - `POST /wp-json/wp-custom-gpt/v1/rooms` (logged-in users)
  - `POST /wp-json/wp-custom-gpt/v1/rooms/{id}` (rename)
  - `DELETE /wp-json/wp-custom-gpt/v1/rooms/{id}`
  - `GET /wp-json/wp-custom-gpt/v1/rooms/{roomId}/chats`
  - `POST /wp-json/wp-custom-gpt/v1/rooms/{roomId}/chats`
  - `GET /wp-json/wp-custom-gpt/v1/chats/{chatId}/messages`
  - `POST /wp-json/wp-custom-gpt/v1/chats/{chatId}/messages`
- Shortcode shell: `[wp_custom_gpt]`
- Separate settings shortcode: `[wp_custom_gpt_settings]`

## Install in WordPress

1. Copy the `wp_custom_gpt` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP Custom GPT** in WordPress admin.
3. Create a page and place the shortcode `[wp_custom_gpt]`.
4. Create a second page and place the shortcode `[wp_custom_gpt_settings]` for parameter management.
5. Open both pages while logged in.

## Settings management interface

Use shortcode `[wp_custom_gpt_settings]` to manage:

- API key
- Prompt ID
- Vector store IDs
- User email

Storage location is WordPress database (`wp_options`) using these option keys:

- `wpcgpt_api_key`
- `wpcgpt_prompt_id`
- `wpcgpt_vector_store_ids`
- `wpcgpt_user_email`

Permission model:

- Read/write settings requires `manage_options` capability.
- Settings endpoint is not exposed to non-admin users.

## Build a loadable WordPress archive

Use the build script to generate a ZIP that can be uploaded in WordPress via Plugins > Add New > Upload Plugin.

1. Run the script from the plugin root:

```bash
bash scripts/build-plugin-zip.sh
```

2. The archive is created in `dist/` with the naming pattern:

```text
dist/wp-custom-gpt-<version>.zip
```

3. In WordPress admin, upload that ZIP file:
  Plugins > Add New > Upload Plugin

### Notes

- The script reads the version from `wp-custom-gpt.php`.
- It excludes local build artifacts and `.git` metadata.
- Required tool in your shell: `rsync`.
- Archive creation uses `zip` when available, otherwise falls back to `powershell.exe` with `Compress-Archive`.

## Next implementation slices

1. OpenAI service port and send-message orchestration.
2. Rule flow engine + collect_contact handler.
3. Basic admin settings page UI.
