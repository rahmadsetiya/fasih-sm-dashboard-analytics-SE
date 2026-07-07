---
name: deploy
description: Deploy the application to staging or production. Run with /deploy staging or /deploy production.
disable-model-invocation: true
---

# Deploy Skill

Triggered by: `/deploy $ARGUMENTS` (e.g. `/deploy staging` or `/deploy production`)

## Steps

1. **Confirm target**: Read `$ARGUMENTS` to determine the deploy target (staging or production). If not specified, ask the user which environment to deploy to.

2. **Pre-deploy checks**: Run the full CI check first and stop if it fails:
   ```bash
   composer run ci:check
   ```

3. **Build frontend assets**:
   ```bash
   npm run build
   ```

4. **Deploy**: Execute the deployment command for the target environment.
   - **NOTE**: The actual deploy command is not yet configured. Ask the user what command to run (e.g. `php artisan deploy`, `rsync`, `git push heroku`, SSH to server, etc.) and fill in this step.

5. **Post-deploy**: Run any necessary post-deploy artisan commands (e.g. `php artisan migrate --force`, `php artisan config:cache`, `php artisan route:cache`, `php artisan view:cache`).

6. **Confirm**: Report success or failure to the user with a summary of what was deployed.

---

*Fill in the actual deploy command in step 4 before using this skill.*
