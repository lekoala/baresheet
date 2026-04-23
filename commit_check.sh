echo "Current branch: $(git rev-parse --abbrev-ref HEAD)"
echo "Diff to origin/main:"
git diff origin/main
echo "Log of empty commit:"
git log -1
