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
  - `POST /wp-json/wp-custom-gpt/v1/chats/{chatId}/send` (stores user message, calls OpenAI, stores assistant message)
- Room management shortcode: `[wp_custom_gpt_rooms]`
- Chat management shortcode: `[wp_custom_gpt_chats]`
- Single chat shortcode: `[wp_custom_gpt_chat]`
- Legacy alias: `[wp_custom_gpt]` (same as room management)
- Separate settings shortcode: `[wp_custom_gpt_settings]`

## Install in WordPress

1. Copy the `wp_custom_gpt` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP Custom GPT** in WordPress admin.
3. Create a page for rooms and place:

```text
[wp_custom_gpt_rooms chats_page="/chat-verwaltung/"]
```

4. Create a page for chat management (selected room) and place:

```text
[wp_custom_gpt_chats rooms_page="/raum-verwaltung/" chat_page="/chat/"]
```

5. Create a page for a single chat and place:

```text
[wp_custom_gpt_chat chats_page="/chat-verwaltung/"]
```

Shortcode page targets accept:

- absolute URL (for example `https://localhost/eure-welt-ev/chat-verwaltung/`)
- path or slug (for example `/chat-verwaltung/` or `chat-verwaltung`)
- WordPress page ID (for example `42`)

Paths/slugs are resolved against your WordPress home URL automatically.
If a matching WordPress page exists, the plugin uses its real permalink (recommended).
For maximum reliability, use page IDs in shortcode attributes.

6. Create a settings page and place:

```text
[wp_custom_gpt_settings]
```

7. Open pages while logged in.

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

## Quick chat test with OpenAI

1. Open a page with shortcode `[wp_custom_gpt_settings]` as admin and save at least:
  - API key
  - (optional) Prompt ID
  - (optional) Vector store IDs
2. Open the room page (`[wp_custom_gpt_rooms ...]`) as logged-in user.
3. Create a room and click Enter.
4. On chat management page (`[wp_custom_gpt_chats ...]`) create a new chat or continue an existing one.
5. You will land on the single chat page (`[wp_custom_gpt_chat ...]`).
6. Send a message using the message box and `Send to OpenAI`.
7. The plugin persists both user and assistant messages in WordPress DB.

## Build a loadable WordPress archive

Use the build script to generate a ZIP that can be uploaded in WordPress via Plugins > Add New > Upload Plugin.

1. Run the script from the plugin root:

```bash
bash scripts/build-plugin-zip.sh
```

Version bump options:

```bash
# default: patch bump (x.y.z -> x.y.z+1)
bash scripts/build-plugin-zip.sh --patch

# minor bump (x.y.z -> x.y+1.0)
bash scripts/build-plugin-zip.sh --minor

# major bump (x.y.z -> x+1.0.0)
bash scripts/build-plugin-zip.sh --major
```

2. The archive is created in `dist/` with the naming pattern:

```text
dist/wp-custom-gpt-<version>.zip
```

3. In WordPress admin, upload that ZIP file:
  Plugins > Add New > Upload Plugin

### Notes

- The script increments the plugin version on each execution (`--patch`, `--minor`, `--major`) and writes it back to `wp-custom-gpt.php`.
- It excludes local build artifacts and `.git` metadata.
- Required tool in your shell: `rsync`.
- Archive creation uses `zip` when available, otherwise falls back to `python3` zip creation.
- If both are unavailable, install `zip` or `python3` in WSL.
- The archive always contains the same top-level plugin folder name `wp-custom-gpt`.
- This stable folder name is required so WordPress can replace an existing installation during plugin upload.

## Next implementation slices

1. OpenAI service port and send-message orchestration.
2. Rule flow engine + collect_contact handler.
3. Basic admin settings page UI.
