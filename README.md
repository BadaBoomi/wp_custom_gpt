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

## Install in WordPress

1. Copy the `wp_custom_gpt` folder into your WordPress `wp-content/plugins/` directory.
2. Activate **WP Custom GPT** in WordPress admin.
3. Create a page and place the shortcode `[wp_custom_gpt]`.
4. Open the page while logged in.

## Next implementation slices

1. OpenAI service port and send-message orchestration.
2. Rule flow engine + collect_contact handler.
3. Basic admin settings page UI.
