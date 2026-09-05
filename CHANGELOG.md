# v1.2.0
## 09/05/2026

1. [](#new)
    * Postmark now answers the Email plugin's provider contract, so anything on the site can ask this plugin how Postmark's delivery webhooks are checked and read, what a sending domain's DNS has to say, and what this transport does to custom headers. The webhook reader moved here out of the KahunaCart newsletter add-on, which no longer has to carry a parser per provider.
    * A **Set up** button's worth of API: an add-on that receives delivery reports can now have this plugin create the webhook in Postmark from the server token already pasted in, with the right triggers, on the right message stream, and with the basic auth pair set on both ends. Pressing it again updates the webhook already at that address rather than adding a second one.
    * New settings: **Message Stream**, for a store that sends on a stream of its own; **Webhook Username** and **Webhook Password**, the basic auth Postmark posts with, checked on every delivery report; and an optional **Account Token**, which lets the deliverability checks read back the DKIM selector and return path Postmark has on file for a sending domain.
    * `message_stream` is now sent on the transport DSN, so a store on its own stream sends on it. Left empty or set to `outbound`, the transport is exactly what it was.
    * The metadata key a send id travels in is now named by the Email plugin rather than by this one. It is `Grav-Send-Id`, from `X-Grav-Send-Id`, or whatever `providers.send_header` in the Email plugin's configuration says with its leading `X-` taken off and capped at the twenty characters Postmark allows a metadata key. So the header to set on an SMTP send is `X-PM-Metadata-Grav-Send-Id`, where it used to be `X-PM-Metadata-KahunaCart-Send` — another product's name in a Team Grav plugin. Rename the header once and both ends follow.

2. [](#improved)
    * Help text on every settings field, saying which of Postmark's two kinds of token belongs where — a server token and an account token are refused where the other belongs, however right they look, and that is the commonest thing to get wrong here.
    * A test suite, with the webhook reader run against Postmark's own documented sample payloads.

# v1.1.0
## 05/01/2026

1. [](#improved)
    * Added 1.7|2.0 compatibility flags
    * Folded in pending working-tree changes (vendor refresh / minor PHP 8 modernization)

# v1.0.1
## 05/09/2023

1. [](#bugfix)
   * fix null config bug

# v1.0.0
## 05/09/2023

1. [](#new)
   * Initial public release

# v0.1.0
##  10/01/2022

1. [](#new)
    * ChangeLog started...
