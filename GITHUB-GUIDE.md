# GitHub for Noychoy — Beginner's Guide (zero experience)

Git = a "save history" system for your code. GitHub = the website that stores those
saves online, so your PC and your server can share the same code.

Your project is **already a Git repository** on the `main` branch. You just need to
connect it to GitHub once, then use 3 commands forever after.

---

## ONE-TIME SETUP

### 1. Make a GitHub account
Go to <https://github.com> → Sign up (free).

### 2. Tell Git who you are (run once on your PC)
Open **Git Bash** (installed with Git) in the project folder and run — use your own
name and the email you signed up with:
```bash
git config --global user.name "Your Name"
git config --global user.email "you@example.com"
```

### 3. Create an empty repo on GitHub
On github.com → click **+** (top right) → **New repository**:
- **Repository name:** `noychoy-store`
- Keep it **Private**
- **Do NOT** check "Add a README" (the project already has files)
- Click **Create repository**

GitHub shows you a URL like `https://github.com/yourname/noychoy-store.git`. Copy it.

### 4. Connect your project to that repo (run once)
In Git Bash, inside the project folder:
```bash
git remote add origin https://github.com/yourname/noychoy-store.git
```

### 5. First upload (push)
```bash
git add .
git commit -m "Initial version of Noychoy store"
git push -u origin main
```
The first push asks you to log in. Easiest way: install **GitHub CLI**
(<https://cli.github.com>) and run `gh auth login`, OR when the password box appears
paste a **Personal Access Token** (GitHub → Settings → Developer settings → Personal
access tokens → generate one with "repo" scope). A normal password will NOT work.

✅ Your code is now on GitHub.

---

## EVERYDAY USE — saving & uploading changes

Any time you (or I) change the code, run these **3 commands** in Git Bash:
```bash
git add .
git commit -m "Short note about what changed"
git push
```
- `git add .` → gather all changes
- `git commit -m "..."` → save a snapshot with a note
- `git push` → upload it to GitHub

That's it. Repeat forever.

---

## UPDATING THE LIVE WEBSITE

After you `git push` from your PC, log into the server (cPanel → Terminal / SSH) and run:
```bash
cd ~/noychoy-store
bash deploy.sh
```
That pulls your changes and refreshes the site. (See [DEPLOY.md](DEPLOY.md) for what it does.)

So the full loop is:
```
edit on PC  →  git add . && git commit -m "..." && git push   →   on server: bash deploy.sh
```

---

## HANDY EXTRAS
- See what changed before saving: `git status`
- Undo changes to a file you haven't committed: `git checkout -- path/to/file`
- See your history: `git log --oneline`

## ⚠️ Important
Never commit the `.env` file or the `vendor` / `node_modules` folders — they're
already excluded in `.gitignore`, so just don't remove those lines.
