@echo off
REM Endgame — create and migrate the endgame_test database.
REM Run once before the first `vendor\bin\phpunit` invocation.
REM Requires psql and createdb on PATH (default XAMPP PostgreSQL install).
REM
REM Usage:
REM   tests\setup-test-db.bat
REM   tests\setup-test-db.bat myuser mypassword   (override user/password)

set PGUSER=%1
if "%PGUSER%"=="" set PGUSER=postgres

set PGPASSWORD=%2
if "%PGPASSWORD%"=="" set PGPASSWORD=

echo [1/8] Creating database endgame_test (ignored if already exists)...
createdb -U %PGUSER% endgame_test 2>nul
if %ERRORLEVEL% NEQ 0 (
    echo      (database already exists — continuing)
)

echo [2/8] Running schema.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\schema.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [3/8] Running 002_phase2.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\002_phase2.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [4/8] Running 003_phase3.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\003_phase3.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [5/8] Running 004_formats.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\004_formats.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [6/8] Running 005_teams.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\005_teams.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [7/8] Running 006_sse.sql ...
"C:\Program Files\PostgreSQL\16\bin\psql.exe" -U %PGUSER% -d endgame_test -f migrations\006_sse.sql
if %ERRORLEVEL% NEQ 0 goto :error

echo [8/8] Done. endgame_test is ready for tests.
goto :eof

:error
echo ERROR: migration failed (exit code %ERRORLEVEL%).
exit /b 1
