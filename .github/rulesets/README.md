# Rulesets

GitHub rulesets are configured through the API or **Settings → Rules → Rulesets**,
not applied automatically from the repo. The JSON files here are kept as the
source of truth so the configuration is reproducible and reviewable.

To apply [protect-main.json](protect-main.json):

```bash
gh api --method POST \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --input .github/rulesets/protect-main.json
```

Or import it in the UI: **Settings → Rules → Rulesets → New ruleset → Import**.
