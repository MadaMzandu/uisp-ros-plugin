# Webhook setup, compatibility mode, and rationale

## Why authentication changed

Webhooks can trigger account changes, suspension, reactivation, and removal of managed RouterOS resources. The previous entry point allowed requests based on an authenticated session or a local/private source address. A private address does not establish which application sent a request, particularly when traffic passes through a proxy or an internal container network.

This release separates the two access paths:

- **Administrative actions** require a UISP administrator session with the appropriate plugin permissions. A private source address no longer grants access.
- **Service/client webhooks** require a dedicated shared secret by default, alongside request and event validation. The sender must supply the same key configured in the plugin.

The secret is a plugin authentication choice, not a new UISP or MikroTik requirement. An explicit **Allow webhooks without a secret (compatibility)** option preserves operation without this extra check. It is off by default and never turns on automatically when a key is missing or wrong. Enabling it changes webhook authentication; it does not grant access to administrative operations or disable automatic suspension/reactivation processing.

The shared secret authenticates possession of a key. It is not an HMAC signature or a RouterOS password. Duplicate-event handling remains a separate responsibility of the durable job queue.

## Choose the mode

| Mode | Plugin setting | Delivery endpoint | Behavior |
| --- | --- | --- | --- |
| Authenticated, default | Compatibility **off**; save a secret of at least 32 characters | Send the matching token in the URL or a supported header | Reject a missing or incorrect token before provisioning |
| Compatibility | Compatibility **on** | The plain `public.php` URL is sufficient | Accept valid service/client events without authenticating their sender; retain event validation and panel permissions |

Compatibility mode allows anyone who can reach the endpoint to attempt valid events. Use the explicit checkbox to select it; clearing the secret alone does not enable it.

## Authenticated setup

### 1. Generate the key

Generate a random secret with a password manager, or run this PowerShell command locally. It produces 64 hexadecimal characters from 32 cryptographically random bytes:

```powershell
$webhookBytes = New-Object byte[] 32
$webhookRandom = [System.Security.Cryptography.RandomNumberGenerator]::Create()
$webhookRandom.GetBytes($webhookBytes)
$webhookRandom.Dispose()
[BitConverter]::ToString($webhookBytes).Replace('-', '').ToLowerInvariant()
```

This key is generated for this integration. Do not obtain it from MikroTik, reuse the router password, or substitute a UISP API key. Keep it out of shared screenshots, logs, and PR text.

### 2. Save only the key in the plugin

In UISP's plugin configuration, enter the generated key in **Webhook secret / Secreto del webhook** (`webhookSecret`), leave **Allow webhooks without a secret / Permitir webhooks sin secreto (compatibilidad)** disabled, and save.

| Location | What to enter |
| --- | --- |
| Plugin's secret field | **Only the raw key** — no URL, parameter name, or quotes |
| UISP Webhooks endpoint URL | The complete URL containing that same key as `webhookToken` |

### 3. Configure the UISP endpoint

In **Webhooks → Endpoints / Terminales**, edit the endpoint directed to this plugin:

```text
https://YOUR_SERVER/crm/_plugins/ros-plugin/public.php?webhookToken=YOUR_GENERATED_SECRET
```

Replace the placeholders. If the URL already has a query string, append the token with `&` instead of a second `?`. Updating the plugin's secret does not change UISP's existing endpoint URL.

The hexadecimal key above can be placed in the URL directly. If using an existing key containing symbols, percent-encode its value for the URL, for example with `[Uri]::EscapeDataString($yourWebhookSecret)`. Keep the **original unencoded key** in the plugin. Characters such as `+`, `&`, `#`, `%`, and `?` must not accidentally become URL syntax or change the delivered value.

Keep the endpoint active and subscribed to the required service/client events. If an automatically generated endpoint cannot be edited, configure an equivalent manual endpoint and avoid keeping duplicate deliveries active.

If the sender supports headers, it may instead send `X-Webhook-Token: YOUR_GENERATED_SECRET` or `Authorization: Bearer YOUR_GENERATED_SECRET`. Precedence is **Bearer → X-Webhook-Token → URL token**. Remove or update an old header when switching to a URL token; a conflicting higher-priority header can cause rejection.

### 4. Verify a new delivery

Send a fresh event or use UISP's available redelivery action. Saving a key does not automatically replay events previously rejected by the plugin. Inspect the response for this plugin's endpoint, rather than the aggregate result from multiple endpoints.

An authentication rejection returns HTTP **403** with `unauthorized`. The plugin records a bounded reason in `webhook_rejected`:

| Reason | Meaning | Action |
| --- | --- | --- |
| `webhook_secret_not_configured` | No usable configured secret, including a key shorter than 32 characters | Save a valid key in the plugin, or deliberately select compatibility mode |
| `webhook_secret_contains_url` | The secret field contains a full URL | Replace it with only the raw key |
| `webhook_token_missing` | This delivery supplied no token | Configure the endpoint URL or a supported header |
| `webhook_token_mismatch` | The supplied and configured keys differ | Check both values, URL encoding, and header precedence |

Malformed JSON follows the request-validation error path and can return HTTP 400. An unauthenticated payload that fails the webhook schema can return HTTP 403 without a `webhook_rejected` record. Check entity, action, UUID, and ID before treating every 403 as a key mismatch; a matching key cannot repair an invalid event.

## Operate without the extra secret check

1. Enable **Permitir webhooks sin secreto (compatibilidad)** (`webhookAllowWithoutSecret`) in the plugin settings and save.
2. The endpoint can use `https://YOUR_SERVER/crm/_plugins/ros-plugin/public.php` without a token. The stored secret does not need deleting; it is not checked while compatibility is enabled.
3. Test a fresh service/client event and verify its provisioning outcome.

POST/JSON validation, valid event identifiers, job ordering, duplicate handling, and administrator permissions remain in place. This option keeps the webhook integration active; it removes only the shared-secret requirement for valid webhook events.

To return to authenticated mode, first configure a valid key and matching endpoint token, then **disable compatibility**, save, and test a fresh delivery. Rotating a key requires coordinating both settings; they are not synchronized automatically.

## Confirm suspension and reactivation

HTTP 200 establishes neither a completed RouterOS change nor a successful traffic block. Inspect `data.provisioning`, `jobKeys`, and `jobCounts`, together with the `webhook_result` or `webhook_processing_failed` log:

| Outcome | Interpretation |
| --- | --- |
| `applied` | The execution confirmed its commands; verify the account/profile, session, and traffic |
| `pending` | Work remains; inspect its last error and retry state in Jobs |
| `already_applied` / `duplicate_completed` | The state or delivery was already handled; no extra write may be needed |
| `cancelled` | A previously cancelled delivery is acknowledged without replaying its old operation |
| `ignored_unmanaged` / `deferred` / `credentials_requested` | The service is documentary, deferred, or awaiting credentials; inspect the reported context |
| `cleared` / `failed` | Owned-resource removal completed, or processing failed, respectively |

Suspend one test service, wait for its outcome, and only then reactivate it. Confirm that the PPP account references the intended suspended/active profile and that the session reconnects. The suspension profile's address list must be used by the installation's firewall rules to block traffic.

UISP may send a billing event and an associated status-only `edit`. Once the first event is fully applied and the desired state still matches, the second may report `already_applied`; the presence of two deliveries does not require two account writes or disconnections. A completed job may disappear from the pending Jobs list.

Check the relevant RouterOS logging topics and the actual profile/session state. A missing visible log line alone does not establish that no change occurred. The first webhook attempt runs without waiting for cron; pending failures retain their scheduled/manual retry behavior.
