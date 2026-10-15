# B54 security review — help center

| Area | Risk | Control | Test |
|---|---|---|---|
| Restricted articles | Store staff or shoppers read internal runbooks or developer help, or learn their titles through search, counts or page help | HelpAudiencePolicy filters every query before matching; 404 for articles a reader may not open; categories and counts come from readable articles only | role-aware visibility test |
| Draft leakage | Unreviewed text reaches readers | Only published/deprecated articles are readable; edits wait as revisions; a second person approves | review workflow test |
| Self-approval | One person writes and publishes misleading content | Approval refused for the version's author (`self_review`); every step audited | review workflow test |
| Script in articles | Stored XSS through Markdown or staff edits | Raw HTML escaped, unsafe links dropped by CommonMark, then the allow-list sanitizer; only code/pre/table added to its list | rendering test |
| Secrets in documentation | Keys, passwords or personal data published (§47) | Checker in CI and in the editor: keys, tokens, long secrets, password values, real emails, mobile numbers; nothing loads while a file fails | checker test, bundle refusal test |
| Broken or misleading links | Links to pages or API paths that do not exist; links to articles some readers cannot open | Route, OpenAPI path, webhook catalog and audience checks | checker test |
| Feedback and search privacy | Personal data collected in analytics (§29) | No reader identity; emails and long numbers masked; feedback purged with the store; retention by `help:maintain` | feedback and search tests |
| Editor access | Anyone on the platform changes help | `help` capability on every write route (PlatformRoles::ROUTES); reads need `view` | capability test |
| Closed stores | Help unreachable when it is needed most | `/help/*` exempt from EnsureStoreOperational; MFA requirement unchanged | closed store test |

No secret, credential or dependency added. `league/commonmark` was already installed with Laravel.
