#!/bin/bash

WP="wp --allow-root"
DATA_PATH="/usr/src/data"
DATA_FILENAME="wordcamp_dev.sql"

# Entry point.
#
# $1 The database command to execute.
#
function main() {
    local cmd="$1"

    cd /usr/src/public_html

    case "$cmd" in
        backup-current ) backup_current;;
        clean )          clean;;
        import )         import ${@:2};;
        export )         export;;
        restore )        restore ${@:2};;

        # Clean up the current state of the database and then export it to the provision file.
        clean-export )
            clean
            export
        ;;

        # Delete the current state of the database and then re-import the provision file.
        reset )
            _reset
            import --skipbackup
        ;;

        help | * )
            echo "Please enter a valid command."
            echo
            echo -e "  backup-current \n  clean \n  clean-export \n  import \n  export \n  reset \n  restore <file>"
        ;;
    esac

    echo

    cd -
}

# Clean unnecessary data from the database.
#
# This currently includes post revisions and transients.
#
function clean() {
    local blog_ids=( $( $WP site list --format=ids ) )

    echo

    for (( i=0; i<${#blog_ids[@]}; i++ )); do
		local blog_id=${blog_ids[$i]}
		local blog_url="$( ${WP} site url ${blog_id} )"
        local blog_cmd="${WP} --url=${blog_url}"

        echo -n "Cleaning $blog_url... "

        echo -n "post revisions... "
        local revision_ids=$( ${blog_cmd} post list --post_type='revision' --format=ids )
        if [ ! -z "$revision_ids" ]; then
            $blog_cmd post delete $revision_ids --force --quiet
        fi

        echo -n "trashed posts... "
        local post_ids=$( ${blog_cmd} post list --post_type='any' --post_status='trash' --format=ids )
        if [ ! -z "$post_ids" ]; then
            $blog_cmd post delete $post_ids --force --quiet
        fi

        echo -n "transients... "
        $blog_cmd transient delete --all --quiet

        echo "Done."
	done

    echo -n "Cleaning network... "

    echo -n "camptix logs... "
    $WP db query "TRUNCATE TABLE wc_camptix_log;" --quiet

    echo -n "transients... "
    $WP transient delete --all --network --quiet

    echo "Done."

    echo -n "Repair + optimize... "
    $WP db repair --quiet
    $WP db optimize --quiet
    echo "Done."

    echo
}

# Back up the current state of the database.
#
function backup_current() {
    local timestamp=$(date +%s)

    echo
    echo -n "Backing up current database to ${timestamp}-current-${DATA_FILENAME}.bkp... "
    $WP db export ${DATA_PATH}/${timestamp}-modified-${DATA_FILENAME}.bkp --quiet
    echo "Done."
}

# Back up the database dump file used to provision the database.
#
function _backup_provision() {
    local timestamp=$(date +%s)

    echo
    echo -n "Backing up database provision file to ${timestamp}-provision-${DATA_FILENAME}.bkp... "
    cp ${DATA_PATH}/${DATA_FILENAME} ${DATA_PATH}/${timestamp}-provision-${DATA_FILENAME}.bkp
    echo "Done."
}

# Import the database provision file.
#
function import() {
    local skipbackup="$1"

    if [ ! "$skipbackup" = "--skipbackup" ]; then
        backup_current
    fi

    echo
    echo -n "Importing database from provision file ${DATA_FILENAME}... "
    $WP db import ${DATA_PATH}/${DATA_FILENAME} --quiet
    echo "Done."
}

# Export the current state of the database as the new database provision file.
#
function export() {
    if [ -f ${DATA_PATH}/${DATA_FILENAME} ]; then
        _backup_provision
    fi

    echo
    echo -n "Exporting database to provision file ${DATA_FILENAME}... "
    $WP db export ${DATA_PATH}/${DATA_FILENAME} --quiet
    echo "Done."
}

# Delete the current state of the database, after backing it up first.
#
function _reset() {
    backup_current

    echo
    echo -n "Removing all database tables... "
    $WP db reset --yes --quiet
    echo "Done."
}

# Import a specified file to the database after deleting the current state.
#
function restore() {
    local file="$1"

    if [ ! -f $file ]; then
        # Try prepending the data path.
        file="${DATA_PATH}/${file}"
        echo "$file"
    fi

    if [ -f $file ]; then
        _reset

        echo "Importing database from file ${file}... "
        $WP db import $file
        echo "Done."
    else
        echo "File $file does not exist."
    fi
}

main $@
