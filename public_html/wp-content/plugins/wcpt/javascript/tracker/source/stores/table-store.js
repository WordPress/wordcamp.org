let filters = {
	searchQuery: '',
	sortOrder: 'asc',
};

module.exports = {
	/**
	 * Apply all filters to the given rows
	 *
	 * @param {Object} options
	 * @return {Array}
	 */
	getFilteredRows( options ) {
		if ( ! options.sortField ) {
			return [];
		}

		filters = Object.assign( filters, options );

		const filteredRows = this._searchRows( wpcApplicationTracker.applications, filters.searchQuery );

		filteredRows.sort( this._sortRows );

		return filteredRows;
	},

	/**
	 * Filter the given rows by the current search query
	 *
	 * @param {Array}  rows
	 * @param {string} searchQuery
	 * @return {Array}
	 */
	_searchRows( rows, searchQuery ) {
		const hits = [];

		if ( '' === searchQuery ) {
			return rows;
		}

		rows.forEach(
			function( row ) {
				for ( const field in row ) {
					if ( ! row.hasOwnProperty( field ) ) {
						continue;
					}

					if (
						-1 !==
						row[ field ]
							.toString()
							.toLowerCase()
							.indexOf( searchQuery.toLowerCase() )
					) {
						hits.push( row );
						break;
					}
				}
			}.bind( this )
		);

		return hits;
	},

	/**
	 * Callback for Array.prototype.sort() that sorts alphabetically by a field
	 *
	 * @param {Object} a
	 * @param {Object} b
	 * @return {number}
	 */
	_sortRows( a, b ) {
		a = a[ filters.sortField ].toString().toLowerCase();
		b = b[ filters.sortField ].toString().toLowerCase();

		if ( a > b ) {
			return 'asc' === filters.sortOrder ? 1 : -1;
		}

		if ( a < b ) {
			return 'asc' === filters.sortOrder ? -1 : 1;
		}

		return 0;
	},
};
