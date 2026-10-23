# wp_custom_gpt

WordPress plugin scaffold for porting core functionality from pwa_custom_gpt.

## Current implementation status

- Plugin bootstrap and activation hook
- Database migration with core tables:
  - `wpcgpt_rooms`
  - `wpcgpt_chats`
  - `wpcgpt_messages`
  - `wpcgpt_flow_sessions`
- Configuration-based chat types with per-room quota enforcement
- Room attribute configuration with ordered display and filtered visibility
- Attribute prerequisites and provision rules per chat type
- Initial greeting generation for each new chat type via the `Prompt` field
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
  - `POST /wp-json/wp-custom-gpt/v1/transient/send` (Transient Mode only; non-persistent conversation turn)
- Room management shortcode: `[wp_custom_gpt_rooms]`
- Chat management shortcode: `[wp_custom_gpt_chats]`
- Single chat shortcode: `[wp_custom_gpt_chat]`
- Legacy alias: `[wp_custom_gpt]` (same as room management)
- Separate settings shortcode: `[wp_custom_gpt_settings]`
- Admin menu page: `Custom GPT Flows` (manage_options only)

## Install in WordPress

1. Copy the `wp_custom_gpt` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP Custom GPT** in WordPress admin.
3. Create a page for rooms and place:

```text
[wp_custom_gpt_rooms chats_page="/chat-verwaltung/" width="100%" max_width="1200px"]
```

4. Create a page for chat management (selected room) and place:

```text
[wp_custom_gpt_chats rooms_page="/raum-verwaltung/" chat_page="/chat/" width="100%" max_width="1200px"]
```

5. Create a page for a single chat and place:

```text
[wp_custom_gpt_chat chats_page="/chat-verwaltung/" width="100%" max_width="1200px"]
```

The app shortcodes support these sizing attributes:

- `width` (default `100%`)
- `max_width` (default `980px`)
- `min_width` (default `0`)

Use them to let the widget expand to the surrounding page width or to constrain it to a custom maximum width.

Example with a wider chat pane:

```text
[wp_custom_gpt_chat width="100%" max_width="1400px" min_width="320px"]
```

Optional Transient Mode usage (no room, no persistence):

```text
[wp_custom_gpt_chat config_id="3"]
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
- Transient Mode flag
- General settings
- Chat type tab with configuration entries (`ID`, `Zweck`, `Prompt`, `Max per room`, `Attr prereq`, `Attr prov`, `Prompt-ID`, `Icon`)
- Room attribute tab with ordered room attribute definitions and visibility control
- Default assistant icon (upload/select from WordPress media library)
- Appearance tab for fonts/colors used by chat, chat management and room management

Important behavior:

- `Prompt` is no longer a generic text prefill. It is the first assistant message shown when a new chat of that configuration is created.
- `Max per room` restricts how many chats of that type may exist in a single room. Once the limit is reached, the type is no longer offered in the chat creation selector.
- `Attr prereq` limits creation of a chat type to rooms where all selected attributes already exist and are non-empty.
- `Attr prov` declares room attributes that this chat type provides. When the chat is deleted, those attributes are removed from the room only if no other remaining chat in the same room still provides them.
- The order in `Raum-Attribute` is the order used for room attribute display; undocumented attributes are hidden entirely.

Storage location is WordPress database (`wp_options`) using these option keys:

- `wpcgpt_api_key`
- `wpcgpt_starters`
- `wpcgpt_configuration_entries`
- `wpcgpt_assistant_icon_id`
- `wpcgpt_ui_theme`
- `wpcgpt_transient_mode_enabled`

Permission model:

- Read/write settings requires `manage_options` capability.
- Settings endpoint is not exposed to non-admin users.

## Flow management (admin)

The plugin now includes a central admin-only page:

- WordPress admin menu: `Custom GPT Flows`
- Capability required: `manage_options`

It stores custom flow handlers in DB table `wpcgpt_flow_code` and executes them through the flow runtime when an active flow session is running.
Flow-related uploads are stored in DB table `wpcgpt_flow_files` and physically in WordPress uploads under `wpcgpt-flow-files/<flow_type>/`.

### Flow REST endpoints (admin only)

- `GET /wp-json/wp-custom-gpt/v1/flows`
- `GET /wp-json/wp-custom-gpt/v1/flows/{flowType}`
- `POST /wp-json/wp-custom-gpt/v1/flows/{flowType}`
- `DELETE /wp-json/wp-custom-gpt/v1/flows/{flowType}`
- `POST /wp-json/wp-custom-gpt/v1/flows/validate`
- `GET /wp-json/wp-custom-gpt/v1/flows/{flowType}/files`
- `POST /wp-json/wp-custom-gpt/v1/flows/{flowType}/files` (multipart/form-data, field name: `file`)
- `DELETE /wp-json/wp-custom-gpt/v1/flows/{flowType}/files/{fileId}`

### Flow file uploads

On the `Custom GPT Flows` admin page you can upload and manage files per flow type.

- Max file size: 10 MB
- Allowed file extensions: `xlsx`, `xls`, `csv`, `ods`, `tsv`, `txt`, `json`
- Files are namespaced by flow type

### Flow code contract

Stored code must be a PHP function body (without `<?php`), compiled into:

```php
static function(array $context): array { /* your code */ }
```

Expected context fields:

- `mode`: `initial` or `turn`
- `chat_id`: current chat id
- `user_id`: current user id
- `user_input`: current user text (`turn` mode)
- `session`: `{ flow_type, state }`
- `flow_files`: list of uploaded files for the current `flow_type` including `id`, `original_name`, `mime_type`, `size_bytes`, `relative_path`, `url`, `absolute_path`

Expected return values:

- `initial_prompt` (string) for `initial` mode and/or `assistant_reply` (string)
- `status`: `running` | `completed` | `aborted`
- `state`: array to persist in `wpcgpt_flow_sessions.state_json`

### Triggering a flow

In assistant output, add a directive:

```text
[[start_rule_flow:collect_contact]]
```

Behavior:

- Directive is removed from visible assistant message.
- Flow session is started for that chat.
- Runtime requests `initial` mode and stores the returned initial prompt as assistant message.
- Following user turns use the active flow runtime instead of OpenAI until status is `completed` or `aborted`.

### Configuration entries on the settings page

The settings page (`[wp_custom_gpt_settings]`) contains an editable table of configuration entries with the columns:

```text
| ID | Zweck | Prompt | Max per room | Attr prereq | Attr prov | Prompt-ID | Icon |
```

Rows can be added and removed manually and are saved with the settings form.

- `ID` is stable and can be used by the `config_id` shortcode parameter of `[wp_custom_gpt_chat ...]`.
- `Prompt` is the initial assistant greeting shown as the first message when a new chat of this type is created.
- `Max per room` limits how many chats of that configuration can exist inside the same room. When the limit is reached, the entry is hidden from the chat-type selector.
- `Attr prereq` lists room attributes that must already be defined in the room before a new chat of this type can be created.
- `Attr prov` lists attributes this chat type provides to the room. They are removed when the last chat of this type in the room is deleted, unless some other chat in the same room still provides them.
- `Icon` can be uploaded/selected per configuration row and overrides the global assistant icon for chats created with that configuration.

Entries are stored structurally in `wpcgpt_configuration_entries` and synchronized to the legacy markdown option `wpcgpt_starters` for backward compatibility.

### Room attributes configuration

The settings page includes a dedicated tab `Raum-Attribute` where you can define which room attributes exist and in which order they should be rendered. The order is used for display in room listings; attributes missing from this list are hidden from room display entirely.

Only attribute names configured here are available as possible selections in `Attr prereq` and `Attr prov` for chat types.

### Appearance customization (settings tab)

The settings page includes a dedicated tab `Farben und Schriften` with:

- Default assistant icon upload/select/remove
- Font family selector (predefined list)
- Font size
- Color selectors for all theme colors used by:
  - `[wp_custom_gpt_chat]`
  - `[wp_custom_gpt_chats]`
  - `[wp_custom_gpt_rooms]`

The old read-only markdown textarea view for starters has been removed.

### Configuration selection when creating a chat

On the chat management page (`[wp_custom_gpt_chats ...]`) an entry can be selected when creating a new chat. The selected
entry is stored with the chat (`config_label`, `config_prompt`, `config_prompt_id`, `config_icon_url`) and defines:

- the `Prompt-ID` used for every OpenAI request of that chat (without one, `gpt-4.1-mini` is used),
- the initial assistant greeting, which is inserted as the first assistant message when the chat is created,
- the assistant icon for this chat (if set on the selected configuration entry),
- whether a rule-based flow starts: if the prompt contains `[[start_rule_flow:<flow_type>]]`, the flow is started directly at
  chat creation.

The chat-type selector automatically filters out entries whose `Max per room` limit has already been reached for the current room.
Configuration entries are no longer rendered as buttons above the chat input; only `[[buttons:...]]` tokens from assistant
replies produce buttons there.

## Quick chat test with OpenAI

1. Open a page with shortcode `[wp_custom_gpt_settings]` as admin and save at least:
  - API key
  - (optional) configuration entries with `Prompt-ID`
2. Open the room page (`[wp_custom_gpt_rooms ...]`) as logged-in user.
3. Create a room and click Enter.
4. On chat management page (`[wp_custom_gpt_chats ...]`) create a new chat or continue an existing one.
5. You will land on the single chat page (`[wp_custom_gpt_chat ...]`).
6. Send a message using the message box and `Send to OpenAI`.
7. The plugin persists both user and assistant messages in WordPress DB.

## Transient Mode (non-persistent chat)

`Transient Mode` can be enabled on `[wp_custom_gpt_settings]`.

If enabled, you can create a dedicated transient chat page with:

```text
[wp_custom_gpt_chat config_id="<ID>"]
```

Where `<ID>` is the configuration row ID shown in the settings table.

Transient Mode behavior:

- No messages are persisted in plugin chat/message tables.
- No room relation is required or used.
- No user/customer relation is required or used for the conversation data.
- Conversation history is kept only in browser memory for the current page session.
- The selected configuration controls prompt/prompt-id and optional assistant icon override.

Notes:

- The endpoint used is `POST /wp-json/wp-custom-gpt/v1/transient/send`.
- If transient mode is disabled, transient endpoint usage is rejected.
- `config_id` requires an existing configuration row ID.

### Inline response buttons above chat input

The chat page supports assistant inline button tokens like:

```text
[[buttons:[Label 1|Message text 1],[Label 2|Message text 2]]]
```

Behavior:

- Tokens are removed from visible assistant text output.
- Buttons are rendered above the chat input field.
- Clicking a button fills the input with the mapped message text.

If no inline response buttons are present in the latest assistant message, the chat page falls back to initial buttons built from saved configuration starters.

### Model selection behavior

When the chat has a Prompt-ID from its selected configuration entry, the plugin sends the request with `prompt.id` and does not force a model.
This avoids conflicts such as `reasoning.mode is not supported with this model` caused by overriding prompt-defined behavior with an incompatible hardcoded model.

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

## Recent changes

### vNext

- Added `Max per room` to each configuration entry in the settings table.
- Enforcement is active in the room chat selector and on the server-side chat creation endpoint.
- A configuration entry is hidden from the selector if its quota for the current room has already been reached.
- The `Prompt` field now acts as the first assistant greeting when a new chat of that type is created.
- The initial greeting is stored as the first assistant message in the new chat.
- Existing functionality for flow directives and inline assistant buttons remains intact.

## Current implementation notes

- The room-scoped `Max per room` limit is enforced both in the UI selector and on the server-side chat creation flow.
- The `Prompt` value functions as the initial greeting for a newly created chat, rather than as a message-box prefill.
- Rule-flow directives and inline response buttons remain supported in assistant output.
