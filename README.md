# Email Postmark Plugin

The **Email Postmark** plugin is an extension for [Grav CMS](https://github.com/getgrav/grav). It lets the [Email plugin](https://github.com/getgrav/grav-plugin-email) send through [Postmark](https://postmarkapp.com), over their API or over their SMTP servers, and it tells the rest of the site what Postmark can report back about what happened to each message.

## Installation

Installing the Email Postmark plugin can be done in one of three ways: the GPM (Grav Package Manager) installation method lets you install the plugin with a terminal command, the manual method lets you do so via a zip file, and the admin method lets you do so via the Admin plugin.

### GPM Installation (Preferred)

To install the plugin via the [GPM](https://learn.getgrav.org/cli-console/grav-cli-gpm), through your system's terminal (also called the command line), navigate to the root of your Grav installation and enter:

    bin/gpm install email-postmark

This will install the Email Postmark plugin into your `/user/plugins` directory within Grav. Its files can be found under `/your/site/grav/user/plugins/email-postmark`.

### Admin Plugin

If you use the Admin plugin, you can install the plugin directly by browsing the `Plugins` menu and clicking on the `Add` button.

## Configuration

Copy `user/plugins/email-postmark/email-postmark.yaml` to `user/config/plugins/email-postmark.yaml` and edit that copy, or use the Admin plugin, which writes the same file.

```yaml
enabled: true
transport: api          # 'api' or 'smtp'
api_token:              # the server token, from that server's API Tokens tab
message_stream: outbound
account_token:          # optional, for the deliverability checks
basic_user:             # optional, the webhook's basic auth
basic_password:
```

Then set the Email plugin's Mail Engine to **Postmark**.

**Two kinds of Postmark token, and confusing them is the commonest mistake.** The **server token** is per server, is on that server's own API Tokens tab, and is the one that goes in `api_token` — it sends the mail and it creates the delivery webhook. The **account token** is on Account → API Tokens, is for account-wide things, and is the one that goes in `account_token`. Each is refused where the other belongs, however right it looks.

## Delivery reports

Postmark can post back what happened to every message — delivered, bounced, marked as spam, opened, clicked — and this plugin knows how to read those posts. There is no report for a message Postmark refused to send: an address on Postmark's suppression list is refused by the send itself, with a 406 and an `InactiveRecipient` error, and no webhook follows. An add-on that keeps a mailing list, such as the KahunaCart newsletter, asks the Email plugin for a provider, gets this one, and from then on a bounce lands on the right address in the store's own records without anybody copying anything between two dashboards. Nothing here posts anything anywhere on its own: this plugin reads what arrives and answers the questions an add-on asks.

**What a store gets once it is set up.** Bounces suppress the address that bounced, so a campaign stops trying to reach it. Spam complaints do the same. Deliveries, opens and clicks fill in the figures on a campaign. Without a webhook, a store can tell you a campaign was sent and nothing at all about what happened to it.

**The one button.** The add-on that receives the reports gives you an address and a Set up button. Pressing it creates the webhook in Postmark for you, using the server token already in this plugin's settings, on the message stream this plugin sends on, with the five triggers ticked and the basic auth below set on it. Pressing it again updates the webhook already at that address rather than adding a second one beside it.

**By hand, if you would rather.** In Postmark, open the server this site sends through, go to its Webhooks tab and press Add webhook. Paste the address the add-on gave you into the Webhook URL box, tick Delivery, Bounce, Spam complaint, Open and Click, and save. If you set a username and password under Basic auth on that screen, put the same pair into Webhook Username and Webhook Password here so the site can check them.

**Postmark does not sign its webhooks**, and says so. So there is no signing key to paste. What protects the address is the long random secret in it, and the basic auth pair if you set one. Both are worth having; either on its own is reasonable.

**Tying a bounce back to the exact message.** Postmark returns no headers in any of its webhooks, on any record type, and there is no setting that turns them on. What it does return is metadata, and on the **SMTP** transport a header named `X-PM-Metadata-Grav-Send-Id` becomes metadata that comes back on every event. On the **API** transport that header is sent as an ordinary header and does not become metadata, so a store that wants events tied to a particular send should set Transport to SMTP. Everything else works the same either way.

## Deliverability

Postmark's SPF include is `spf.mtasv.net`, and a custom return path CNAMEs into `pm.mtasv.net`. There is no zone for DKIM, because Postmark publishes the key itself as a TXT record rather than having your selector point into a zone of theirs — which means a site cannot guess your selector. If you put an **account token** into the settings, a site can read the selector and the return path Postmark has on file for your sending domain and check them for you. Without one, everything else still works and the checks say plainly that they could not look.

## Credits

Sending is [Symfony Mailer](https://symfony.com/doc/current/mailer.html)'s Postmark bridge. The webhook reader came out of the KahunaCart newsletter add-on, where it was one of six a store had to carry.
