/* Vacanam Pro: AI translation in the admin. */
jQuery( function ( $ ) {
	var cfg = window.vacanamproAI || {}, t = cfg.i18n || {};

	function call( action, data ) {
		return $.post( cfg.ajax, $.extend( { action: 'vacanampro_ai_' + action, nonce: cfg.nonce }, data ) );
	}
	function fail( $status, xhr ) {
		var msg = xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message ? xhr.responseJSON.data.message : ( xhr && xhr.data && xhr.data.message ? xhr.data.message : 'Request failed' );
		$status.text( t.error + ' ' + msg ).addClass( 'is-error' );
	}

	// Run a post translation step by step until it is done.
	function runPost( postId, $status, done ) {
		call( 'post', { post: postId } ).done( function ( r ) {
			if ( ! r.success ) { return fail( $status, r ); }
			if ( 'more' === r.data.status ) {
				$status.text( t.left.replace( '%d', r.data.left ) );
				return runPost( postId, $status, done );
			}
			done();
		} ).fail( function ( xhr ) { fail( $status, xhr ); } );
	}

	// Editor box.
	$( document ).on( 'click', '.vacanampro-ai-translate', function () {
		var $box = $( this ).closest( '.vacanampro-ai-box' ), $status = $box.find( '.vacanampro-ai-status' ).removeClass( 'is-error' );
		if ( ! window.confirm( t.confirm ) ) { return; }
		$( this ).prop( 'disabled', true );
		$status.text( t.working );
		runPost( $box.data( 'post' ), $status, function () {
			$status.text( t.done );
			window.location.reload();
		} );
	} );

	// Text in images.
	function imageRow( postId, img ) {
		var $row = $( '<div class="vacanampro-ai-img"></div>' );
		$row.append( $( '<figure></figure>' ).append( $( '<img alt="">' ).attr( 'src', img.url ), $( '<figcaption></figcaption>' ).text( t.original ) ) );
		var $right = $( '<div class="vacanampro-ai-img-right"></div>' ).appendTo( $row );
		function showMade( id, url ) {
			$right.empty().append( $( '<figure></figure>' ).append( $( '<img alt="">' ).attr( 'src', url ), $( '<figcaption></figcaption>' ).text( t.translated ) ) );
			$( '<button type="button" class="button button-primary"></button>' ).text( t.use ).appendTo( $right ).on( 'click', function () {
				var $b = $( this ).prop( 'disabled', true );
				call( 'image_use', { post: postId, from: img.id, to: id } ).done( function ( r ) {
					if ( r.success ) { $b.replaceWith( $( '<p class="description"></p>' ).text( t.used ) ); } else { $b.prop( 'disabled', false ); }
				} );
			} );
			$( '<button type="button" class="button-link"></button>' ).text( t.discard ).appendTo( $right ).on( 'click', function () {
				call( 'image_discard', { post: postId, image: id } ).always( function () { showMake(); } );
			} );
		}
		function showMake() {
			$right.empty();
			var $s = $( '<p class="vacanampro-ai-status"></p>' );
			$( '<button type="button" class="button"></button>' ).text( t.make ).appendTo( $right ).on( 'click', function () {
				$( this ).prop( 'disabled', true );
				$s.removeClass( 'is-error' ).text( t.drawing );
				call( 'image_make', { post: postId, image: img.id } ).done( function ( r ) {
					if ( r.success ) { showMade( r.data.id, r.data.url ); } else { fail( $s, r ); $right.find( 'button' ).prop( 'disabled', false ).text( t.again ); }
				} ).fail( function ( xhr ) { fail( $s, xhr ); $right.find( 'button' ).prop( 'disabled', false ).text( t.again ); } );
			} );
			$right.append( $s );
		}
		if ( img.is_made ) { $right.text( t.translated ); } else if ( img.translated ) { showMade( img.translated, img.turl ); } else { showMake(); }
		return $row;
	}
	$( document ).on( 'click', '.vacanampro-ai-images', function () {
		var $box = $( this ).closest( '.vacanampro-ai-box' ), $list = $box.find( '.vacanampro-ai-image-list' ).empty(), postId = $box.data( 'post' );
		call( 'images', { post: postId } ).done( function ( r ) {
			if ( ! r.success ) { return; }
			if ( ! r.data.images.length ) { $list.text( t.noImages ); return; }
			$.each( r.data.images, function ( i, img ) { $list.append( imageRow( postId, img ) ); } );
		} );
	} );

	// Whole site.
	var stop = false;
	$( document ).on( 'click', '.vacanampro-ai-stop', function () { stop = true; } );
	$( document ).on( 'click', '.vacanampro-ai-start', function () {
		var $box = $( this ).closest( '.vacanampro-ai-bulk' ), $status = $box.find( '.vacanampro-ai-status' ).removeClass( 'is-error' ), $log = $box.find( '.vacanampro-ai-log' ).empty(), $bar = $box.find( '.vacanampro-ai-bar span' ).css( 'width', 0 );
		var lang = $box.find( '.vacanampro-ai-lang' ).val(), what = $box.find( '.vacanampro-ai-what:checked' ).map( function () { return this.value; } ).get();
		var $start = $( this ).prop( 'disabled', true ), $stop = $box.find( '.vacanampro-ai-stop' ).prop( 'disabled', false );
		stop = false;
		$status.text( t.queue );
		call( 'queue', { lang: lang, what: what, only_new: $box.find( '.vacanampro-ai-new' ).is( ':checked' ) ? 1 : '' } ).done( function ( r ) {
			if ( ! r.success ) { fail( $status, r ); $start.prop( 'disabled', false ); return; }
			var jobs = r.data.jobs, i = 0;
			if ( ! jobs.length ) { $status.text( t.nothing ); $start.prop( 'disabled', false ); $stop.prop( 'disabled', true ); return; }
			function next() {
				$bar.css( 'width', ( 100 * i / jobs.length ) + '%' );
				$status.text( t.progress.replace( '%1$d', i ).replace( '%2$d', jobs.length ) );
				if ( stop || i >= jobs.length ) {
					$start.prop( 'disabled', false ); $stop.prop( 'disabled', true );
					if ( i >= jobs.length ) { $status.text( t.finished + ' ' + t.progress.replace( '%1$d', i ).replace( '%2$d', jobs.length ) ); }
					return;
				}
				var job = jobs[ i ];
				call( 'job', { type: job.type, id: job.id, lang: job.lang || lang } ).done( function ( res ) {
					if ( res.success && 'more' === res.data.status ) { return next(); }
					$( '<li></li>' ).text( ( res.success ? '✓ ' : '✕ ' ) + job.label + ( res.success ? '' : ' – ' + ( res.data && res.data.message ? res.data.message : '' ) ) ).appendTo( $log );
					i++;
					next();
				} ).fail( function ( xhr ) {
					$( '<li></li>' ).text( '✕ ' + job.label ).appendTo( $log );
					i++;
					next();
				} );
			}
			next();
		} ).fail( function ( xhr ) { fail( $status, xhr ); $start.prop( 'disabled', false ); } );
	} );

	// Texts screen.
	$( document ).on( 'click', '.vacanampro-ai-strings button', function () {
		var $p = $( this ).closest( '.vacanampro-ai-strings' ), $status = $p.find( '.vacanampro-ai-status' ).removeClass( 'is-error' ).text( t.working );
		$( this ).prop( 'disabled', true );
		call( 'strings', { lang: $p.data( 'lang' ) } ).done( function ( r ) {
			if ( ! r.success ) { return fail( $status, r ); }
			$status.text( t.done );
			window.location.reload();
		} ).fail( function ( xhr ) { fail( $status, xhr ); } );
	} );
} );
