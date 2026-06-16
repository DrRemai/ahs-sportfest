@echo off
REM Endgame — run PHPUnit test suite.
REM
REM Usage:
REM   tests\run.bat                 — run all three suites
REM   tests\run.bat Formats         — run only the Formats suite
REM   tests\run.bat Permissions     — run only the Permissions suite
REM   tests\run.bat Api             — run only the Api suite
REM   tests\run.bat --filter Foo    — pass any phpunit flag directly
REM
REM Prerequisite: run `tests\setup-test-db.bat` once to create endgame_test,
REM and `composer install` to install phpunit into vendor/.

set ROOT=%~dp0..
set PHPUNIT=%ROOT%\vendor\bin\phpunit

if not exist "%PHPUNIT%" (
    echo ERROR: vendor\bin\phpunit not found. Run `composer install` first.
    exit /b 1
)

REM If a suite name or flag is supplied, pass it through; otherwise run all.
if "%~1"=="" (
    php "%PHPUNIT%" --configuration "%ROOT%\phpunit.xml"
) else (
    REM Check if first arg looks like a known suite name (no leading dash)
    echo %~1 | findstr /r "^-" >nul
    if errorlevel 1 (
        php "%PHPUNIT%" --configuration "%ROOT%\phpunit.xml" --testsuite "%~1" %2 %3 %4 %5
    ) else (
        php "%PHPUNIT%" --configuration "%ROOT%\phpunit.xml" %*
    )
)
