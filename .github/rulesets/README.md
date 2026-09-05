# Rulesets

GitHub rulesets are configured through the API or **Settings → Rules → Rulesets**,
not applied automatically from the repo. The JSON files here are kept as the
source of truth so the configuration is reproducible and reviewable.

`main` is protected by **two** rulesets rather than one. Bypass actors bypass a
whole ruleset, never a single rule — so the review requirement is kept in its own
ruleset that repository admins bypass, while the branch protections and the `test`
status check live in a ruleset nobody bypasses.

| File | What it enforces | Who bypasses |
| --- | --- | --- |
| [protect-main.json](protect-main.json) | No deletion, no force-push, linear history, `test` must pass | nobody |
| [require-review.json](require-review.json) | PR required, 1 approval, threads resolved, squash-only | repository admins, on pull requests only |

The admin bypass uses `bypass_mode: "pull_request"`, so admins can merge their own
PR without an approval once CI is green, but still cannot push straight to `main` —
the rule that forces changes through a PR is the one being bypassed, and only in
the context of a PR.

To create them:

```bash
gh api --method POST \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --input .github/rulesets/protect-main.json

gh api --method POST \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --input .github/rulesets/require-review.json
```

To update one that already exists, look up its id and `PUT` to it:

```bash
gh api /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --jq '.[] | "\(.id)\t\(.name)"'

gh api --method PUT \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets/RULESET_ID \
  --input .github/rulesets/protect-main.json
```

Or import a file in the UI: **Settings → Rules → Rulesets → New ruleset → Import**.
