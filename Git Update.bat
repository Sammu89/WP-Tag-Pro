@echo off
setlocal EnableDelayedExpansion

rem ========================================
rem Git Sync Utility for Windows
rem Same behaviour as your Linux version
rem ========================================

:title
cls
echo ========================================
echo     Git Sync Utility (Windows)
echo     Repo: %cd%
echo ========================================
echo.
echo What do you want to do?
echo [1] Update LOCAL from SERVER (force discard all local changes)
echo [2] Update SERVER from LOCAL (git push)
echo.
set "CHOICE="
set /p CHOICE="Select 1 or 2: "

if "%CHOICE%"=="1" goto PULL
if "%CHOICE%"=="2" goto PUSH
echo Invalid choice.
pause
goto end

:PULL
echo.
echo ========================================
echo  FORCING local repo to match remote...
echo  Discarding ALL local changes...
echo ========================================
echo.

rem === THIS IS THE FIX - force reset BEFORE any merge can fail ===
git fetch origin main
git reset --hard origin/main
git clean -fd

echo.
echo Local repository successfully forced to latest remote version.
echo.
echo Current commit:
git log --oneline -1

rem === Optional: auto npm run build if node_modules exists ===
if exist "node_modules\" (
  echo.
  echo **node_modules detected - running npm run build...**
  call npm run build
  if !errorlevel! EQU 0 (
    echo Build completed successfully.
  ) else (
    echo ERROR: Build failed.
  )
) else (
  echo.
  echo No node_modules folder found. Skipping build ^(intentional^)
)

echo.
pause
goto end

:PUSH
echo.
echo ========================================
echo  PUSH: Updating remote with local changes
echo ========================================
echo.

git status -s
echo.
set "CONFIRM="
set /p CONFIRM="Stage and push ALL local changes? (Y/N): "
if /i NOT "%CONFIRM%"=="Y" (
  echo Operation cancelled.
  pause
  goto end
)

git add .

rem Commit message
set "MSG="
set /p MSG="Enter commit message (or press Enter for auto): "
if "%MSG%"=="" (
  for /f "tokens=2 delims==." %%a in ('"wmic OS Get localdatetime /value"') do set "dt=%%a"
  set "MSG=Auto commit !dt:~0,4!-!dt:~4,2!-!dt:~6,2!_!dt:~8,4!"
)

echo Committing with message: "%MSG%"
git commit -m "%MSG%" || echo Nothing to commit - skipping.

echo.
echo Pushing to origin/main ...
git push origin main
if !errorlevel! NEQ 0 (
  echo.
  echo ERROR: Push failed!
  pause
  exit /b 1
)

echo.
echo Remote updated successfully.
pause
goto end

:end
endlocal
