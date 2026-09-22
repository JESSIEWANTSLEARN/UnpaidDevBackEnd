WalangBrownOut Terminal / PowerShell Guide
1. Important rule when copying commands
Only paste the actual command.
Do not paste terminal output such as:
PS C:\...
>>
CategoryInfo
FullyQualifiedErrorId
Those are outputs/prompts, not commands.

2. Current project locations
Backend
cd C:\WalangBrownOut\UnpaidDevBackEnd
Laravel itself is inside:
cd framework
Frontend
cd C:\WalangBrownOut\UnpaidDevFrontEnd
Our current architecture is:
React/Vite Frontend
http://127.0.0.1:5173
        ↓
Laravel API
http://127.0.0.1:8000
        ↓
Local MySQL

3. Start the backend locally
From the backend repository:
cd C:\WalangBrownOut\UnpaidDevBackEnd\framework

php artisan optimize:clear

php artisan serve --host=127.0.0.1 --port=8000
Normal shorter version:
cd framework
php artisan serve
Usually Laravel starts at:
http://127.0.0.1:8000
Keep this terminal open.

4. Start the frontend locally
Open a second PowerShell window:
cd C:\WalangBrownOut\UnpaidDevFrontEnd

npm install
npm run dev
Usually:
http://127.0.0.1:5173
npm install does not need to be run every time. Usually only:
npm run dev

5. XAMPP / MySQL
Make sure MySQL is running in XAMPP.
Useful test:
Test-NetConnection 127.0.0.1 -Port 3306
Check Laravel database connection:
cd C:\WalangBrownOut\UnpaidDevBackEnd\framework

php artisan migrate:status
But because your friend will receive a complete SQL dump, do not automatically run:
php artisan migrate
until the imported database has been inspected.

6. Laravel storage
Create the public storage link:
php artisan storage:link
If there is an old/broken link locally and you intentionally need to recreate it:
Remove-Item public\storage -Force -Recurse -ErrorAction SilentlyContinue
php artisan storage:link
Check:
Test-Path public\storage
Expected:
True
Your old command:
Remove-Item public\hot -ErrorAction SilentlyContinue
is different. public\hot is a Vite development marker file. It can be removed when Laravel thinks a Vite dev server is running when it actually is not.
Check:
Test-Path public\hot

7. Clear Laravel cache
Very useful after .env, routes, config, or authentication changes:
php artisan optimize:clear
This clears:
config cache
route cache
view cache
application cache

8. Backend test commands
These are the important ones we have been using.
Laravel automated tests
php artisan test
Route check
php artisan route:list
Smaller output:
php artisan route:list | Select-Object -Last 20
Check one PHP file
Example:
php -l routes\web.php
Or:
php -l app\Http\Controllers\SomeController.php

9. Scan ALL project PHP files for syntax errors
Your old command only scanned:
app/
This improved version scans the important Laravel source folders while avoiding vendor.
Run from:
UnpaidDevBackEnd\framework
$folders = @(
    "app",
    "routes",
    "config",
    "database"
)

$failed = $false

foreach ($folder in $folders) {
    Get-ChildItem $folder -Recurse -File -Filter *.php | ForEach-Object {
        php -l $_.FullName

        if ($LASTEXITCODE -ne 0) {
            $failed = $true
        }
    }
}

if ($failed) {
    Write-Host "`nPHP SYNTAX SCAN FAILED." -ForegroundColor Red
} else {
    Write-Host "`nALL PHP FILES PASSED SYNTAX CHECK." -ForegroundColor Green
}
This is closer to what you mean by:
"scan the system through terminal and find whether files have errors."
It checks PHP syntax across:
app/
routes/
config/
database/
It intentionally does not scan:
vendor/
node_modules/
because those are third-party dependencies.

10. Composer checks
Check composer.json:
composer validate --no-check-publish
Check installed PHP dependencies:
composer install
Security vulnerability check:
composer audit
composer audit may report dependency vulnerabilities; that is different from a syntax error.

11. Frontend checks
From:
UnpaidDevFrontEnd
Production build
npm run build
This is one of our most important frontend checks.
Success looks like:
✓ built in ...
A warning such as:
Some chunks are larger than 500 kB
is a warning, not a build failure.
Dependency check
npm audit
Install dependencies
npm install
If node_modules is damaged:
Remove-Item node_modules -Recurse -Force
npm install
Don't do this routinely; only when needed.

12. Git safety checks
These are extremely useful.
Current branch
git branch --show-current
Modified files
git status --short
Example:
M framework/Dockerfile
M app/SomeFile.php
See actual changes
git diff
Specific file:
git diff -- framework/Dockerfile
Summary
git diff --stat
Detect whitespace mistakes
git diff --check
We use this frequently before commits.

13. Check staged changes
After git add:
git diff --cached
Check staged whitespace:
git diff --cached --check
See staged file summary:
git diff --cached --stat

14. Stage files correctly
Do not normally use:
git add .
Instead:
git add framework/app/SomeFile.php
git add framework/routes/web.php
Or frontend:
git add resources/js/pages/MyPage.jsx
This prevents unrelated changes from entering the commit.

15. Undo staging without deleting changes
You had this:
git restore --staged -- framework
That's valid.
For one file:
git restore --staged framework/routes/web.php
For everything staged:
git restore --staged .
This does not delete your code changes. It only removes them from staging.

16. Commit
Example:
git commit -m "fix: correct customer order approval"
Then verify:
git status --short
git log -3 --oneline

17. Undo the last LOCAL commit while keeping the code
You had:
git reset --soft HEAD~1
This means:
Undo last commit
BUT
keep all its changes staged
Only use this when the commit is still local or when you understand the consequences.
Don't casually use it on shared/pushed history.

18. Branch workflow
Instead of working directly on main:
git switch main
git pull origin main

git switch -c feature/my-new-feature
Example:
git switch -c fix/customer-order-approval
Then modify → test → commit → push.

19. Push branch
Because GitHub HTTPS has occasionally been flaky on your connection, this worked reliably for us:
git -c http.version=HTTP/1.1 push -u origin YOUR-BRANCH-NAME
Example:
git -c http.version=HTTP/1.1 push -u origin fix/customer-order-approval
Then create a GitHub Pull Request.
Avoid:
git push origin main
for normal development.
Our safer workflow is:
Branch
↓
Commit
↓
Push branch
↓
Pull Request
↓
Review
↓
Merge

20. GitHub connectivity tests
We used these recently.
Check port 443:
Test-NetConnection github.com -Port 443
Check Git directly:
git ls-remote origin
Force HTTP/1.1:
git -c http.version=HTTP/1.1 ls-remote origin
Windows TLS backend:
git -c http.sslBackend=schannel -c http.version=HTTP/1.1 ls-remote origin
Show remote:
git remote -v

21. Cloudflare tunnel
You used:
& "$env:USERPROFILE\Downloads\cloudflared-windows-amd64.exe" tunnel --url http://127.0.0.1:8000
This exposes the local Laravel backend to the internet temporarily.
But for your friend's normal local development:
Cloudflare is NOT required.
He can simply use:
Frontend → 127.0.0.1:5173
Backend  → 127.0.0.1:8000
Cloudflare is useful only when you intentionally need an external device/service to reach the local server.
If Cloudflare has an error, first verify Laravel itself:
Test-NetConnection 127.0.0.1 -Port 8000
Then:
Invoke-WebRequest http://127.0.0.1:8000
Only after local Laravel works should you start Cloudflare.

22. Laravel Tinker
You used:
php artisan tinker
This opens Laravel's interactive console.
Example from our idle timeout test:
app(\App\Services\Auth\AuthSessionService::class)->idleSeconds('System_User');
Expected customer timeout:
60
For staff/admin:
86400
Exit Tinker using:
exit
or:
Ctrl+C

23. Migration commands
See migration state
Safe:
php artisan migrate:status
Local migrations
php artisan migrate
Only when appropriate.
Production
You wrote:
php artisan migrate --force
Be careful with this.
For this project we already discovered that production migration history may not perfectly match the database.
Therefore don't casually run:
php artisan migrate --force
against production.
If a specific production migration is actually required, prefer the targeted form:
php artisan migrate --force --path=database/migrations/EXACT_MIGRATION.php
But only after checking the database/schema situation.

24. VS Code shortcuts
You wrote:
Ctrl + Shift + M
In VS Code this normally opens the Problems panel.
Useful shortcuts:
Ctrl + Shift + M    Problems
Ctrl + `            Terminal
Ctrl + Shift + P    Command Palette
Ctrl + P            Quick file search
Ctrl + F            Find
Ctrl + Shift + F    Search entire project

25. Open VS Code from terminal
Current folder:
code .
Specific folder:
code resources
Your old:
code /http/reousces
looks like a typo.
For frontend resources:
code resources
For Laravel resources:
code framework\resources

26. FULL BACKEND HEALTH CHECK
This is a cleaned-up version of the big script we have used.
Run inside:
UnpaidDevBackEnd\framework
Write-Host "`n=== CLEAR CACHE ===" -ForegroundColor Cyan
php artisan optimize:clear

Write-Host "`n=== MIGRATION STATUS ===" -ForegroundColor Cyan
php artisan migrate:status

Write-Host "`n=== LARAVEL TESTS ===" -ForegroundColor Cyan
php artisan test

Write-Host "`n=== ROUTES ===" -ForegroundColor Cyan
php artisan route:list | Select-Object -Last 20

Write-Host "`n=== PHP SYNTAX SCAN ===" -ForegroundColor Cyan

$folders = @(
    "app",
    "routes",
    "config",
    "database"
)

$failed = $false

foreach ($folder in $folders) {
    Get-ChildItem $folder -Recurse -File -Filter *.php | ForEach-Object {
        php -l $_.FullName

        if ($LASTEXITCODE -ne 0) {
            $failed = $true
        }
    }
}

if ($failed) {
    Write-Host "`nPHP SYNTAX FAILED." -ForegroundColor Red
} else {
    Write-Host "`nPHP SYNTAX PASSED." -ForegroundColor Green
}

Write-Host "`n=== COMPOSER VALIDATION ===" -ForegroundColor Cyan
composer validate --no-check-publish

Write-Host "`n=== GIT DIFF CHECK ===" -ForegroundColor Cyan
git diff --check

Write-Host "`n=== GIT STATUS ===" -ForegroundColor Cyan
git status --short

Write-Host "`n=== GIT DIFF SUMMARY ===" -ForegroundColor Cyan
git diff --stat
That gives you a pretty good backend scanner.

27. FULL FRONTEND HEALTH CHECK
Run inside:
UnpaidDevFrontEnd
Write-Host "`n=== PRODUCTION BUILD ===" -ForegroundColor Cyan
npm run build

Write-Host "`n=== GIT DIFF CHECK ===" -ForegroundColor Cyan
git diff --check

Write-Host "`n=== GIT STATUS ===" -ForegroundColor Cyan
git status --short

Write-Host "`n=== GIT DIFF SUMMARY ===" -ForegroundColor Cyan
git diff --stat
This catches a lot of common frontend mistakes.

28. FULL TWO-REPO SYSTEM CHECK
This is probably the most useful one for your friend.
$backend = "C:\WalangBrownOut\UnpaidDevBackEnd"
$frontend = "C:\WalangBrownOut\UnpaidDevFrontEnd"

Write-Host "`n==============================" -ForegroundColor Cyan
Write-Host "WALANGBROWNOUT SYSTEM CHECK"
Write-Host "==============================" -ForegroundColor Cyan


Write-Host "`n=== BACKEND ===" -ForegroundColor Yellow

Push-Location "$backend\framework"

php artisan optimize:clear

Write-Host "`n--- Laravel Tests ---"
php artisan test

Write-Host "`n--- Migration Status ---"
php artisan migrate:status

Write-Host "`n--- PHP Syntax ---"

$folders = @(
    "app",
    "routes",
    "config",
    "database"
)

$failed = $false

foreach ($folder in $folders) {
    Get-ChildItem $folder -Recurse -File -Filter *.php | ForEach-Object {
        php -l $_.FullName

        if ($LASTEXITCODE -ne 0) {
            $failed = $true
        }
    }
}

if ($failed) {
    Write-Host "PHP syntax errors found." -ForegroundColor Red
} else {
    Write-Host "PHP syntax passed." -ForegroundColor Green
}

Pop-Location


Push-Location $backend

Write-Host "`n--- Backend Git ---"
git diff --check
git status --short

Pop-Location


Write-Host "`n=== FRONTEND ===" -ForegroundColor Yellow

Push-Location $frontend

Write-Host "`n--- Production Build ---"
npm run build

Write-Host "`n--- Frontend Git ---"
git diff --check
git status --short

Pop-Location


Write-Host "`n==============================" -ForegroundColor Cyan
Write-Host "SYSTEM CHECK FINISHED"
Write-Host "==============================" -ForegroundColor Cyan
Your friend only needs to change:
$backend = "..."
$frontend = "..."
to wherever he cloned the repos.

29. What this scanner DOES and DOES NOT prove
This is important for his ChatGPT.
If all commands pass, it means:
✅ PHP syntax valid
✅ Laravel automated tests pass
✅ Laravel can load routes
✅ Migration status can be read
✅ React production bundle compiles
✅ Git has no whitespace errors
But it does not prove:
❌ Every button works
❌ Every role permission is correct
❌ OTP actually arrives
❌ Every database workflow is correct
❌ Every browser/API request works
❌ The UI looks correct
So after terminal checks, we still manually test the website.

30. Our usual development sequence
This is basically how you and I have been working:
1. Inspect existing code
        ↓
2. Create branch
        ↓
3. Change only required files
        ↓
4. git diff
        ↓
5. git diff --check
        ↓
6. PHP syntax / Laravel tests
        ↓
7. npm run build
        ↓
8. Manual browser testing
        ↓
9. git status --short
        ↓
10. Stage only intended files
        ↓
11. git diff --cached --check
        ↓
12. Commit
        ↓
13. Push branch
        ↓
14. Pull Request
        ↓
15. Merge
        ↓
16. Render deploy
        ↓
17. Production QA
That's the procedure I would give your friend's ChatGPT too.

Your simple folder glossary
Your notes here were good. I'd clean them up like this:
app/         = Main Laravel application logic
routes/      = API/web route definitions
config/      = Laravel settings
database/    = migrations, seeders, database-related code
storage/     = Laravel generated/runtime files
storage/logs = Laravel diary/error logs
sessions     = login/session tickets
public/      = publicly accessible backend files
resources/   = source frontend/views/assets
vendor/      = installed PHP libraries
node_modules = installed JavaScript libraries
.env         = private local secrets/settings
tests/       = automated tests
Git          = local version/history system
GitHub       = remote copy + collaboration/history
Render       = production hosting/web server
Railway      = production MySQL database

https://unpaiddevfrontend.onrender.com/login
