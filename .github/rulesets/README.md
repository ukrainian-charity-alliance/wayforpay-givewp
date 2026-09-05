# Rulesets

GitHub rulesets are configured through the API or **Settings → Rules → Rulesets**,
not applied automatically from the repo. The JSON files here are kept as the
source of truth so the configuration is reproducible and reviewable.

[protect-main.json](protect-main.json) protects the default branch: changes must
go through a squashed pull request, `test` must pass, history stays linear, and
the branch cannot be deleted or force-pushed.

Two deliberate choices:

- **`required_approving_review_count` is `0`.** The PR process is still mandatory —
  what is not mandatory is a second pair of eyes, which a two-person repository
  cannot always supply. Raise this to `1` as soon as there are enough reviewers to
  make it realistic.
- **`bypass_actors` is empty.** A bypass actor does not merge normally; they merge
  by explicitly overriding the rules, which is friction on every merge and a habit
  worth not forming. With no rule left to violate on a routine merge, nobody needs
  to override anything. In a genuine emergency, edit or disable the ruleset —
  deliberately, and visibly in the audit log.

To create it:

```bash
gh api --method POST \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --input .github/rulesets/protect-main.json
```

To update it once it exists, look up its id and `PUT` to it:

```bash
gh api /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets \
  --jq '.[] | "\(.id)\t\(.name)"'

gh api --method PUT \
  /repos/ukrainian-charity-alliance/wayforpay-givewp/rulesets/RULESET_ID \
  --input .github/rulesets/protect-main.json
```

Or import the file in the UI: **Settings → Rules → Rulesets → New ruleset → Import**.
