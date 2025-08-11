#!/bin/bash

#############################################################
# MyDatabase Daily Backup Script
# Runs complete backup (database + files + uploads)
# Schedule this script with cron for automated backups
#
# Usage: 
#   ./daily-backup.sh                                    # Auto-detect project path
#   ./daily-backup.sh --project-path=/path/to/project    # Specify project path
#   ./daily-backup.sh --use-sail                         # Auto-detect + use Sail
#############################################################

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"

# Auto-detect project path by removing 'scripts' from the script path
# If script is in /path/to/project/scripts/daily-backup.sh
# Then project path is /path/to/project
if [[ "$SCRIPT_DIR" == */scripts ]]; then
    AUTO_PROJECT_PATH="${SCRIPT_DIR%/scripts}"
else
    AUTO_PROJECT_PATH=""
fi

# Default Configuration
PROJECT_PATH=""
USE_SAIL=false
LOG_FILE=""  # Will be set after project path is determined
DATE=$(date '+%Y-%m-%d %H:%M:%S')

# Parse command line arguments
while [[ $# -gt 0 ]]; do
    case $1 in
        --project-path=*)
            PROJECT_PATH="${1#*=}"
            shift
            ;;
        --project-path)
            PROJECT_PATH="$2"
            shift 2
            ;;
        --use-sail)
            USE_SAIL=true
            shift
            ;;
        --log-file=*)
            LOG_FILE="${1#*=}"
            shift
            ;;
        --help)
            echo "Usage: $0 [options]"
            echo ""
            echo "Options:"
            echo "  --project-path=PATH  Path to MyDatabase project (auto-detected if not specified)"
            echo "  --use-sail          Use Laravel Sail for execution"
            echo "  --log-file=PATH     Custom log file path (default: PROJECT/storage/logs/backup.log)"
            echo "  --help              Show this help message"
            echo ""
            echo "Examples:"
            echo "  $0                                           # Auto-detect project path"
            echo "  $0 --use-sail                                # Auto-detect + use Sail"
            echo "  $0 --project-path=/var/www/mydatabase       # Specify project path"
            echo ""
            if [ -n "$AUTO_PROJECT_PATH" ]; then
                echo "Auto-detected project path: $AUTO_PROJECT_PATH"
            fi
            exit 0
            ;;
        *)
            echo "Unknown option: $1"
            echo "Use --help for usage information"
            exit 1
            ;;
    esac
done

# Use auto-detected path if no project path was specified
if [ -z "$PROJECT_PATH" ]; then
    if [ -n "$AUTO_PROJECT_PATH" ]; then
        PROJECT_PATH="$AUTO_PROJECT_PATH"
        echo "Using auto-detected project path: $PROJECT_PATH"
    else
        echo "Error: Could not auto-detect project path and --project-path was not specified"
        echo "This script should be located in the 'scripts' folder of your project"
        echo "Or specify the path explicitly: $0 --project-path=/path/to/project"
        exit 1
    fi
fi

# Set default log file path if not specified
if [ -z "$LOG_FILE" ]; then
    LOG_FILE="$PROJECT_PATH/storage/logs/backup.log"
    # Create logs directory if it doesn't exist
    mkdir -p "$PROJECT_PATH/storage/logs"
fi

# Function to log messages
log_message() {
    local CURRENT_TIME=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$CURRENT_TIME] $1" >> "$LOG_FILE"
    echo "[$CURRENT_TIME] $1"
}

# Start backup process
log_message "============================================"
log_message "Starting MyDatabase daily backup"
log_message "============================================"

# Validate project directory
if [ ! -d "$PROJECT_PATH" ]; then
    log_message "ERROR: Project directory does not exist: $PROJECT_PATH"
    exit 1
fi

if [ ! -f "$PROJECT_PATH/artisan" ]; then
    log_message "ERROR: Not a valid Laravel project (artisan not found): $PROJECT_PATH"
    exit 1
fi

# Check if .env file exists
if [ ! -f "$PROJECT_PATH/.env" ]; then
    log_message "ERROR: .env file not found in $PROJECT_PATH"
    log_message "Please ensure .env file exists with proper database and backup configuration"
    exit 1
fi

# Check if vendor directory exists (composer dependencies)
if [ ! -d "$PROJECT_PATH/vendor" ]; then
    log_message "ERROR: Vendor directory not found. Dependencies not installed."
    log_message "Please run: cd $PROJECT_PATH && composer install"
    exit 1
fi

# Check storage directory permissions
if [ ! -w "$PROJECT_PATH/storage" ]; then
    log_message "WARNING: Storage directory is not writable by current user"
    log_message "Current user: $(whoami)"
    log_message "Storage owner: $(ls -ld "$PROJECT_PATH/storage" | awk '{print $3":"$4}')"
fi

# Change to project directory
cd "$PROJECT_PATH" || {
    log_message "ERROR: Cannot access project directory: $PROJECT_PATH"
    exit 1
}

# Check if backup is enabled in .env
BACKUP_ENABLED=$(grep "^BACKUP_ENABLED=" "$PROJECT_PATH/.env" | cut -d '=' -f2 | tr -d '"' | tr -d "'")
if [ "$BACKUP_ENABLED" = "false" ]; then
    log_message "ERROR: Backup is disabled in .env file"
    log_message "Set BACKUP_ENABLED=true in your .env file to enable backups"
    exit 1
elif [ -z "$BACKUP_ENABLED" ]; then
    log_message "WARNING: BACKUP_ENABLED not found in .env file"
    log_message "Assuming backups are enabled. Add BACKUP_ENABLED=true to .env for clarity"
fi

# Run the backup command
log_message "Executing backup:all command..."
log_message "Project path: $PROJECT_PATH"

# Execute backup based on configuration
if [ "$USE_SAIL" = true ]; then
    log_message "Using Laravel Sail..."
    # Capture output and error separately for better debugging
    BACKUP_OUTPUT=$(./vendor/bin/sail php artisan backup:all --type=daily 2>&1)
    BACKUP_EXIT_CODE=$?
else
    log_message "Using standard PHP..."
    # Check if PHP is available
    if ! command -v php &> /dev/null; then
        log_message "ERROR: PHP command not found. Please ensure PHP is installed and in PATH"
        exit 1
    fi
    
    # Check PHP version
    PHP_VERSION=$(php -v 2>&1 | head -n 1)
    log_message "PHP Version: $PHP_VERSION"
    
    # Check if we can connect to database before running backup
    DB_CHECK=$(php artisan db:show 2>&1)
    DB_CHECK_EXIT=$?
    if [ $DB_CHECK_EXIT -ne 0 ]; then
        log_message "ERROR: Database connection failed!"
        log_message "Database error details: $DB_CHECK"
        log_message "Please check your database configuration in .env file"
        exit 1
    fi
    
    # Capture output and error separately for better debugging
    BACKUP_OUTPUT=$(php artisan backup:all --type=daily 2>&1)
    BACKUP_EXIT_CODE=$?
fi

# Log the full output for debugging
echo "$BACKUP_OUTPUT" >> "$LOG_FILE"

# Check exit status with detailed error reporting
if [ $BACKUP_EXIT_CODE -eq 0 ]; then
    log_message "SUCCESS: Backup completed successfully"
    
    # Optional: Send success notification
    # echo "MyDatabase backup completed successfully at $DATE" | mail -s "Backup Success" admin@example.com
else
    log_message "ERROR: Backup failed with exit code $BACKUP_EXIT_CODE"
    
    # Parse common Laravel error patterns
    if echo "$BACKUP_OUTPUT" | grep -q "SQLSTATE"; then
        log_message "ERROR TYPE: Database connection/query error detected"
        DB_ERROR=$(echo "$BACKUP_OUTPUT" | grep "SQLSTATE" | head -n 1)
        log_message "Database error: $DB_ERROR"
    elif echo "$BACKUP_OUTPUT" | grep -q "Class.*not found"; then
        log_message "ERROR TYPE: Missing class/dependency error"
        CLASS_ERROR=$(echo "$BACKUP_OUTPUT" | grep "Class.*not found" | head -n 1)
        log_message "Missing class: $CLASS_ERROR"
        log_message "Try running: composer install"
    elif echo "$BACKUP_OUTPUT" | grep -q "Permission denied"; then
        log_message "ERROR TYPE: File permission error"
        log_message "Check permissions on storage directories"
        log_message "Try running: chmod -R 775 storage bootstrap/cache"
    elif echo "$BACKUP_OUTPUT" | grep -q "No such file or directory"; then
        log_message "ERROR TYPE: Missing file or directory"
        FILE_ERROR=$(echo "$BACKUP_OUTPUT" | grep "No such file or directory" | head -n 1)
        log_message "Missing: $FILE_ERROR"
    elif echo "$BACKUP_OUTPUT" | grep -q "memory"; then
        log_message "ERROR TYPE: Memory limit exceeded"
        log_message "Consider increasing PHP memory_limit in php.ini"
    elif echo "$BACKUP_OUTPUT" | grep -q "Backup is disabled"; then
        log_message "ERROR TYPE: Backup system is disabled"
        log_message "Set BACKUP_ENABLED=true in your .env file"
    elif echo "$BACKUP_OUTPUT" | grep -q "S3"; then
        log_message "ERROR TYPE: S3/Cloud storage error"
        log_message "Check your S3 credentials and bucket configuration in .env"
    else
        # If we can't identify the error type, show the last few lines of output
        log_message "ERROR TYPE: Unknown error"
        ERROR_TAIL=$(echo "$BACKUP_OUTPUT" | tail -n 10)
        log_message "Last 10 lines of output:"
        log_message "$ERROR_TAIL"
    fi
    
    log_message "Full error output has been saved to: $LOG_FILE"
    log_message "To investigate further, check:"
    log_message "  1. Laravel logs: $PROJECT_PATH/storage/logs/laravel.log"
    log_message "  2. Environment file: $PROJECT_PATH/.env"
    log_message "  3. Database connection: php artisan db:show"
    log_message "  4. Backup config: php artisan config:show backup"
    
    # Optional: Send failure notification with details
    # echo "MyDatabase backup FAILED at $DATE. Exit code: $BACKUP_EXIT_CODE. Check logs at $LOG_FILE" | mail -s "Backup Failed!" admin@example.com
    
    exit 1
fi

# Optional: Rotate log file if it's too large (keep last 10000 lines)
if [ -f "$LOG_FILE" ]; then
    LOG_SIZE=$(stat -f%z "$LOG_FILE" 2>/dev/null || stat -c%s "$LOG_FILE" 2>/dev/null || echo 0)
    # If log file is larger than 10MB, rotate it
    if [ "$LOG_SIZE" -gt 10485760 ]; then
        tail -n 10000 "$LOG_FILE" > "$LOG_FILE.tmp" && mv "$LOG_FILE.tmp" "$LOG_FILE"
        log_message "Log file rotated (was larger than 10MB)"
    fi
fi

log_message "Backup process completed"
log_message "============================================"

exit 0