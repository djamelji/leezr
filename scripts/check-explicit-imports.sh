#!/usr/bin/env bash
# check-explicit-imports.sh — ADR-045a
# Verifies that components requiring explicit imports are properly imported
# in every .vue file that uses them.

set -euo pipefail

ERRORS=0
ROOT="resources/js"

# Components that require explicit imports (not auto-imported by unplugin-vue-components)
COMPONENTS=(
  # layouts/components/
  "AppShellGate"
  "DefaultLayoutWithHorizontalNav"
  "DefaultLayoutWithVerticalNav"
  "Footer"
  "NavBarNotifications"
  "NavSearchBar"
  "NavbarGlobalWidgets"
  "NavbarShortcuts"
  "NavbarThemeSwitcher"
  "PlatformLayoutWithVerticalNav"
  "PlatformUserProfile"
  "UserProfile"
  # company/components/
  "MemberProfileForm"
  # core/components/
  "DynamicFormRenderer"
)

for COMP in "${COMPONENTS[@]}"; do
  # Find .vue files that use <ComponentName (tag usage)
  FILES=$(grep -rl "<${COMP}" --include="*.vue" "$ROOT" 2>/dev/null || true)

  for FILE in $FILES; do
    # Check if the file has an import or defineAsyncComponent for this component
    if ! grep -qE "(import ${COMP} from|const ${COMP} = defineAsyncComponent)" "$FILE" 2>/dev/null; then
      echo "ERROR: ${FILE} uses <${COMP}> but is missing: import ${COMP} from '...'"
      ERRORS=$((ERRORS + 1))
    fi
  done
done

if [ "$ERRORS" -gt 0 ]; then
  echo ""
  echo "Found ${ERRORS} missing explicit import(s)."
  echo "Components in layouts/components/, company/components/, and core/components/ are NOT auto-imported."
  echo "Add explicit imports for each usage. See ADR-045a."
  exit 1
fi

echo "All explicit imports verified."
exit 0
