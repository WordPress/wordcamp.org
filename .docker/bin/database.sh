#!/bin/bash

WP="wp --allow-root"


function main() {
    local cmd="$1"

    local orig_dir="$PWD"
    cd /usr/src/public_html

    case "$cmd" in
        clean ) clean;;
        export ) export;;

        clean-export )
            clean
            export
        ;;

        * ) echo "Please enter a command.";;
    esac

    cd $orig_dir
}


function clean() {
    local blog_ids=( $( $WP site list --format=ids ) )

    for (( i=0; i<${#blog_ids[@]}; i++ )); do
		local blog_id=${blog_ids[$i]}
		local blog_url="$( ${WP} site url ${blog_id} )"
        local blog_cmd="${WP} --url=${blog_url}"

        echo
        echo -n "Cleaning $blog_url... "

        echo -n "revisions... "
        local revision_ids=$( ${blog_cmd} post list --post_type='revision' --format=ids )
        if [ ! -z "$revision_ids" ]; then
            $blog_cmd post delete $revision_ids --force --quiet
        fi

        echo -n "transients... "
        $blog_cmd transient delete --all --quiet

        echo "Done."
	done

    echo
    echo -n "Cleaning network... "

    echo -n "transients... "
    $WP transient delete --all --network --quiet

    echo "Done."
    echo
}


function export() {
    local path="/usr/src/data"
    local filename="wordcamp_dev.sql"

    if [ -f ${path}/${filename} ]; then
        local timestamp=$(date +%s)

        echo
        echo -n "Moving old database file to ${timestamp}-${filename}.bkp... "
        mv ${path}/${filename} ${path}/${timestamp}-${filename}.bkp
        echo "Done."
    fi

    echo
    echo -n "Exporting database to ${filename}... "
    $WP db export ${path}/${filename} --quiet
    echo "Done."
}

main $@
