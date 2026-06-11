/**
 * STEMageddon LMS front-end behavior.
 *
 * Vanilla JS, no jQuery. Handles: self-enroll, mark-complete, notes save,
 * accordion toggles, and lesson-player tabs. Talks to the sglms/v1 REST API
 * using the nonce localized as sglmsData.
 */
( function () {
	'use strict';

	var data = window.sglmsData || {};

	function api( path, method, body ) {
		return fetch( data.restUrl + path, {
			method: method,
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': data.nonce
			},
			credentials: 'same-origin',
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( res ) {
			return res.json().then( function ( json ) {
				if ( ! res.ok ) {
					throw ( json && json.message ) ? json.message : 'error';
				}
				return json;
			} );
		} );
	}

	function on( selector, evt, handler ) {
		document.addEventListener( evt, function ( e ) {
			var el = e.target.closest( selector );
			if ( el ) {
				handler( e, el );
			}
		} );
	}

	/* Self-enroll (free courses) */
	on( '[data-sglms-enroll]', 'click', function ( e, el ) {
		e.preventDefault();
		var course = el.getAttribute( 'data-sglms-enroll' );
		el.setAttribute( 'disabled', 'disabled' );
		api( 'enroll/' + course, 'POST' ).then( function () {
			window.location.reload();
		} ).catch( function ( msg ) {
			el.removeAttribute( 'disabled' );
			window.alert( msg || ( data.i18n && data.i18n.error ) );
		} );
	} );

	/* Mark lesson complete */
	on( '[data-sglms-complete]', 'click', function ( e, el ) {
		e.preventDefault();
		var lesson = el.getAttribute( 'data-sglms-complete' );
		el.setAttribute( 'disabled', 'disabled' );
		api( 'progress/lesson/' + lesson + '/complete', 'POST' ).then( function ( res ) {
			// Hide the button, show the badge.
			el.setAttribute( 'hidden', 'hidden' );
			var badge = document.querySelector( '[data-sglms-complete-badge]' );
			if ( badge ) {
				badge.removeAttribute( 'hidden' );
			}
			// Update progress UI.
			if ( res.progress ) {
				var bar = document.querySelector( '[data-sglms-progressbar]' );
				var txt = document.querySelector( '[data-sglms-progresstext]' );
				if ( bar ) {
					bar.style.width = res.progress.percent + '%';
				}
				if ( txt ) {
					txt.textContent = res.progress.percent;
				}
			}
			// Tick the current item in the sidebar.
			var current = document.querySelector( '.sglms-navrow.is-current' );
			if ( current ) {
				current.classList.add( 'is-complete' );
				var icon = current.querySelector( '.sglms-navrow__icon' );
				if ( icon && icon.textContent !== '🔒' ) {
					icon.textContent = '✓';
				}
			}
		} ).catch( function ( msg ) {
			el.removeAttribute( 'disabled' );
			window.alert( msg || ( data.i18n && data.i18n.error ) );
		} );
	} );

	/* Save notes */
	on( '[data-sglms-notes-save]', 'click', function ( e, el ) {
		e.preventDefault();
		var lesson = el.getAttribute( 'data-sglms-notes-save' );
		var area = document.querySelector( '[data-sglms-notes="' + lesson + '"]' );
		var status = document.querySelector( '[data-sglms-notes-status]' );
		if ( ! area ) {
			return;
		}
		if ( status ) {
			status.textContent = data.i18n ? data.i18n.saving : '';
		}
		api( 'notes/' + lesson, 'PUT', { content: area.value } ).then( function () {
			if ( status ) {
				status.textContent = data.i18n ? data.i18n.saved : '';
				setTimeout( function () {
					status.textContent = '';
				}, 2000 );
			}
		} ).catch( function ( msg ) {
			if ( status ) {
				status.textContent = msg || ( data.i18n && data.i18n.error );
			}
		} );
	} );

	/* Accordion (course syllabus) */
	on( '.sglms-accordion__head', 'click', function ( e, head ) {
		var body = head.nextElementSibling;
		var expanded = head.getAttribute( 'aria-expanded' ) === 'true';
		head.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		if ( body ) {
			if ( expanded ) {
				body.setAttribute( 'hidden', 'hidden' );
			} else {
				body.removeAttribute( 'hidden' );
			}
		}
	} );

	/* Lesson player tabs */
	on( '[data-sglms-tab]', 'click', function ( e, tab ) {
		var name = tab.getAttribute( 'data-sglms-tab' );
		var root = tab.closest( '.sglms-tabs' );
		if ( ! root ) {
			return;
		}
		root.querySelectorAll( '[data-sglms-tab]' ).forEach( function ( t ) {
			t.classList.toggle( 'is-active', t === tab );
		} );
		root.querySelectorAll( '[data-sglms-panel]' ).forEach( function ( p ) {
			var match = p.getAttribute( 'data-sglms-panel' ) === name;
			p.classList.toggle( 'is-active', match );
			if ( match ) {
				p.removeAttribute( 'hidden' );
			} else {
				p.setAttribute( 'hidden', 'hidden' );
			}
		} );
	} );

	/* ----------------------------------------------------------------- *
	 * Quiz runner
	 * ----------------------------------------------------------------- */

	function el( tag, attrs, text ) {
		var node = document.createElement( tag );
		if ( attrs ) {
			Object.keys( attrs ).forEach( function ( k ) {
				node.setAttribute( k, attrs[ k ] );
			} );
		}
		if ( text != null ) {
			node.textContent = text;
		}
		return node;
	}

	function renderQuestion( q, index ) {
		var wrap = el( 'div', { 'class': 'sglms-q', 'data-qid': q.id } );
		wrap.appendChild( el( 'p', { 'class': 'sglms-q__text' }, ( index + 1 ) + '. ' + q.text ) );

		if ( q.type === 'short' ) {
			wrap.appendChild( el( 'input', { type: 'text', 'class': 'sglms-input sglms-q__short', 'data-answer': q.id } ) );
		} else if ( q.type === 'order' ) {
			var list = el( 'ul', { 'class': 'sglms-q__order', 'data-answer': q.id } );
			( q.options || [] ).forEach( function ( o ) {
				var li = el( 'li', { 'data-oid': o.id } );
				li.appendChild( el( 'span', { 'class': 'sglms-q__ordertext' }, o.text ) );
				var ctrl = el( 'span', { 'class': 'sglms-q__orderctrl' } );
				ctrl.appendChild( el( 'button', { type: 'button', 'class': 'sglms-ord-up', 'aria-label': 'up' }, '↑' ) );
				ctrl.appendChild( el( 'button', { type: 'button', 'class': 'sglms-ord-down', 'aria-label': 'down' }, '↓' ) );
				li.appendChild( ctrl );
				list.appendChild( li );
			} );
			wrap.appendChild( list );
		} else {
			// mc / tf (radio) or multi (checkbox)
			var inputType = ( q.type === 'multi' ) ? 'checkbox' : 'radio';
			( q.options || [] ).forEach( function ( o ) {
				var label = el( 'label', { 'class': 'sglms-q__opt' } );
				var input = el( 'input', { type: inputType, name: 'sglms_' + q.id, value: o.id } );
				input.setAttribute( 'data-answer', q.id );
				label.appendChild( input );
				label.appendChild( el( 'span', null, ' ' + o.text ) );
				wrap.appendChild( label );
			} );
		}

		wrap.appendChild( el( 'p', { 'class': 'sglms-q__feedback', 'data-feedback': q.id, hidden: 'hidden' } ) );
		return wrap;
	}

	function collectAnswers( form ) {
		var answers = {};
		form.querySelectorAll( '.sglms-q' ).forEach( function ( q ) {
			var qid = q.getAttribute( 'data-qid' );
			var short = q.querySelector( '.sglms-q__short' );
			var order = q.querySelector( '.sglms-q__order' );
			if ( short ) {
				answers[ qid ] = short.value;
			} else if ( order ) {
				answers[ qid ] = Array.prototype.map.call( order.querySelectorAll( 'li' ), function ( li ) {
					return li.getAttribute( 'data-oid' );
				} );
			} else {
				var checked = q.querySelectorAll( 'input:checked' );
				if ( q.querySelector( 'input[type="checkbox"]' ) ) {
					answers[ qid ] = Array.prototype.map.call( checked, function ( i ) { return i.value; } );
				} else {
					answers[ qid ] = checked.length ? checked[ 0 ].value : '';
				}
			}
		} );
		return answers;
	}

	function showResults( form, res ) {
		( res.results || [] ).forEach( function ( r ) {
			var q = form.querySelector( '.sglms-q[data-qid="' + r.id + '"]' );
			if ( ! q ) { return; }
			q.classList.remove( 'is-correct', 'is-incorrect' );
			q.classList.add( r.correct ? 'is-correct' : 'is-incorrect' );
			var fb = q.querySelector( '[data-feedback="' + r.id + '"]' );
			if ( fb ) {
				fb.textContent = ( r.correct ? '✓ ' : '✗ ' ) + ( r.feedback || '' );
				fb.removeAttribute( 'hidden' );
			}
		} );
	}

	function initQuiz( root ) {
		var quizId = root.getAttribute( 'data-sglms-quiz' );
		api( 'quiz/' + quizId + '/start', 'POST' ).then( function ( startData ) {
			root.innerHTML = '';
			var form = el( 'div', { 'class': 'sglms-quiz__form' } );
			( startData.questions || [] ).forEach( function ( q, i ) {
				form.appendChild( renderQuestion( q, i ) );
			} );
			root.appendChild( form );

			var submit = el( 'button', { type: 'button', 'class': 'sglms-btn sglms-btn--primary' }, ( data.i18n && data.i18n.submit ) || 'Submit quiz' );
			var result = el( 'div', { 'class': 'sglms-quiz__result', 'aria-live': 'polite' } );
			root.appendChild( submit );
			root.appendChild( result );

			submit.addEventListener( 'click', function () {
				submit.setAttribute( 'disabled', 'disabled' );
				api( 'quiz/' + quizId + '/submit', 'POST', { answers: collectAnswers( form ) } ).then( function ( res ) {
					showResults( form, res );
					result.className = 'sglms-quiz__result ' + ( res.passed ? 'is-pass' : 'is-fail' );
					result.textContent = ( res.passed ? '✓ ' : '✗ ' ) + res.percent + '% (' + res.score + '/' + res.max + ')';
					if ( res.attempts && res.attempts.left !== null ) {
						result.textContent += ' — ' + res.attempts.left + ' left';
					}
					if ( res.attempts && res.attempts.left === 0 ) {
						submit.remove();
					} else {
						submit.removeAttribute( 'disabled' );
					}
				} ).catch( function ( msg ) {
					submit.removeAttribute( 'disabled' );
					window.alert( msg || ( data.i18n && data.i18n.error ) );
				} );
			} );
		} ).catch( function ( msg ) {
			root.innerHTML = '<p>' + ( msg || 'error' ) + '</p>';
		} );
	}

	/* Ordering up/down */
	on( '.sglms-ord-up', 'click', function ( e, btn ) {
		var li = btn.closest( 'li' );
		if ( li && li.previousElementSibling ) {
			li.parentNode.insertBefore( li, li.previousElementSibling );
		}
	} );
	on( '.sglms-ord-down', 'click', function ( e, btn ) {
		var li = btn.closest( 'li' );
		if ( li && li.nextElementSibling ) {
			li.parentNode.insertBefore( li.nextElementSibling, li );
		}
	} );

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '[data-sglms-quiz]' ).forEach( initQuiz );
	} );
}() );
