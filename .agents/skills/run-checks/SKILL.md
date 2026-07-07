---
name: run-checks
description: Run the full CI check suite (npm lint + format + types + phpunit) and report a concise pass/fail summary with actionable errors.
---

# Run Checks Skill

Run the complete CI pipeline and report results.

## Steps

1. Run the full CI check:
   ```bash
   composer run ci:check
   ```

2. Parse the output and report a concise summary in this format:
   - PHP lint (Pint): PASS / FAIL + first failing file if any
   - TypeScript types: PASS / FAIL + first error if any
   - ESLint: PASS / FAIL + first error if any
   - Prettier format: PASS / FAIL
   - PHPUnit: PASS (N tests) / FAIL + failing test name and message

3. If any step fails, show only the most actionable error (not the full raw output) and suggest the fix command:
   - Pint failure → `composer run lint`
   - Prettier failure → `npm run format`
   - ESLint failure → `npm run lint`
   - TypeScript failure → investigate type errors and fix them
   - PHPUnit failure → show the test name and assertion error, then investigate

4. If everything passes, say so clearly in one line.
