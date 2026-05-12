jQuery( document ).ready( function( $ ) {
	'use strict';

	var app = window.WordCampBudgets;

	/*
	 * Model for an attached file
	 */
	app.AttachedFile = Backbone.Model.extend( {
		defaults: {
			'ID':          0,
			'post_parent': 0,
			'filename':    '',
			'url':         ''
		}
	} );

	/*
	 * Collection of attached files
	 */
	app.AttachedFiles = Backbone.Collection.extend( {
		model: app.AttachedFile
	} );

	/*
	 * View for a single attached file
	 */
	app.AttachedFileView = Backbone.View.extend( {
		tagName: 'li',
		template: wp.template( 'wcb-attached-file' ),

		initialize: function() {
			_.bindAll( this, 'render' );
		},

		render: function() {
			$( this.el ).html( this.template( this.model.toJSON() ) );
			return this;
		}
	} );

	/*
	 * View for a collection of attached files
	 */
	app.AttachedFilesView = Backbone.View.extend( {
		initialize: function() {
			var filesList = $( '.wcb_files_list' );

			_.bindAll( this, 'render', 'appendFile' );

			this.collection = new app.AttachedFiles( wcbAttachedFiles );
			this.collection.bind( 'add', this.appendFile );

			filesList.html( '' );  // remove the loading spinner
			filesList.removeClass( 'loading-content' );

			this.render();
		},

		render: function() {
			var self = this;

			_( this.collection.models ).each( function( file ) {
				self.appendFile( file );
			} );
		},

		appendFile: function( file ) {
			if ( ! this.isFileAttachable( file ) ) {
				this.collection.remove( file );
				return;
			}

			var noFilesUploaded  = $( '.wcb_no_files_uploaded' );
			var attachedFileView = new app.AttachedFileView( { model: file } );

			$( '.wcb_files_list' ).append( attachedFileView.render().el );
			noFilesUploaded.removeClass( 'active' );
			noFilesUploaded.addClass( 'hidden' );

			this.trackExistingFile( file );
		},

		/**
		 * Check if a file can be attached to this request.
		 *
		 * Files already attached to other posts are rejected with an error message.
		 *
		 * @param {app.AttachedFile} file
		 * @return {boolean}
		 */
		isFileAttachable: function( file ) {
			if ( 0 !== file.get( 'post_parent' ) && file.get( 'post_parent' ) !== parseInt( $( '#post_ID' ).val(), 10 ) ) {
				$( '.wcb-attached-file-error' ).remove();
				$( '.wcb_files_list' ).after(
					$( '<div class="notice notice-error wcb-attached-file-error"><p></p></div>' )
						.find( 'p' ).text( wcbLocalizedStrings.fileAlreadyAttached ).end()
				);
				return false;
			}

			return true;
		},

		/**
		 * Keep track of existing files that should be attached to the request
		 *
		 * Sometimes users add existing files to the request, rather than uploading new ones. We need to keep track
		 * of those so that they can be attached to the request when the form is submitted.
		 *
		 * @param {app.AttachedFile} file
		 */
		trackExistingFile: function( file ) {
			var fileIDsToAttach,
				existingFilesToAttach = $( '#wcb_existing_files_to_attach' );

			try {
				fileIDsToAttach = JSON.parse( existingFilesToAttach.val() );
			} catch ( exception ) {
				fileIDsToAttach = [];
			}

			if ( -1 === $.inArray( file.get( 'ID' ), fileIDsToAttach ) ) {
				fileIDsToAttach.push( file.get( 'ID' ) );
				existingFilesToAttach.val( JSON.stringify( fileIDsToAttach ) );
			}
		}
	} );

} );
