import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	Button,
	CheckboxControl,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';

const config = window.oddsCompareEditor || {
	markets: { h2h: 'Match result' },
	formats: {},
	defaultFormat: 'decimal',
	settingsUrl: '',
};

/**
 * Turns a key => label object into SelectControl options, with an optional leading entry.
 *
 * @param {Object} map   Key => label.
 * @param {Object} first Optional first option.
 * @return {Array} Options.
 */
const toOptions = ( map, first ) => {
	const options = Object.keys( map ).map( ( value ) => ( {
		value,
		label: map[ value ],
	} ) );
	return first ? [ first, ...options ] : options;
};

export default function Edit( { attributes, setAttributes } ) {
	const {
		sportKey,
		eventId,
		eventTitle,
		market,
		bookmakers,
		format,
		title,
		highlightBest,
		showUpdated,
	} = attributes;

	const [ sports, setSports ] = useState( null );
	const [ events, setEvents ] = useState( [] );
	const [ allBookmakers, setAllBookmakers ] = useState( [] );
	const [ loadingEvents, setLoadingEvents ] = useState( false );
	const [ error, setError ] = useState( null );

	useEffect( () => {
		apiFetch( { path: '/odds-compare/v1/sports' } )
			.then( setSports )
			.catch( ( e ) => setError( e.message ) );
		apiFetch( { path: '/odds-compare/v1/bookmakers' } )
			.then( ( rows ) =>
				setAllBookmakers( rows.filter( ( row ) => row.enabled ) )
			)
			.catch( ( e ) => setError( e.message ) );
	}, [] );

	useEffect( () => {
		if ( ! sportKey ) {
			setEvents( [] );
			return;
		}
		setLoadingEvents( true );
		setError( null );
		apiFetch( {
			path: `/odds-compare/v1/events?sport=${ encodeURIComponent( sportKey ) }`,
		} )
			.then( ( result ) => setEvents( result.events || [] ) )
			.catch( ( e ) => setError( e.message ) )
			.finally( () => setLoadingEvents( false ) );
	}, [ sportKey ] );

	// An empty list means "every enabled bookmaker"; the checklist shows that as all ticked.
	const isChecked = ( key ) =>
		bookmakers.length === 0 || bookmakers.includes( key );

	const toggleBookmaker = ( key, checked ) => {
		const enabledKeys = allBookmakers.map( ( row ) => row.key );
		const current = bookmakers.length ? bookmakers : enabledKeys;
		const next = checked
			? [ ...current, key ]
			: current.filter( ( k ) => k !== key );
		const everything =
			next.length === enabledKeys.length &&
			enabledKeys.every( ( k ) => next.includes( k ) );
		setAttributes( { bookmakers: everything ? [] : next } );
	};

	const sportOptions = toOptions(
		Object.fromEntries(
			( sports || [] ).map( ( s ) => [ s.key, s.title ] )
		),
		{ value: '', label: __( 'Choose a competition…', 'odds-compare-kate' ) }
	);
	const eventOptions = toOptions(
		Object.fromEntries( events.map( ( e ) => [ e.id, e.title ] ) ),
		{ value: '', label: __( 'Choose an event…', 'odds-compare-kate' ) }
	);
	const marketOptions = toOptions( config.markets );
	const formatOptions = toOptions( config.formats, {
		value: '',
		label: sprintf(
			/* translators: %s: format name */
			__( 'Site default (%s)', 'odds-compare-kate' ),
			config.formats[ config.defaultFormat ] || config.defaultFormat
		),
	} );

	const blockProps = useBlockProps();

	return (
		<div { ...blockProps }>
			<InspectorControls>
				<PanelBody title={ __( 'Event', 'odds-compare-kate' ) }>
					{ error && (
						<Notice status="error" isDismissible={ false }>
							{ error }
						</Notice>
					) }
					{ sports === null && ! error && <Spinner /> }
					<SelectControl
						label={ __( 'Competition', 'odds-compare-kate' ) }
						value={ sportKey }
						options={ sportOptions }
						onChange={ ( value ) =>
							setAttributes( {
								sportKey: value,
								eventId: '',
								eventTitle: '',
							} )
						}
					/>
					{ loadingEvents && <Spinner /> }
					<SelectControl
						label={ __( 'Event', 'odds-compare-kate' ) }
						value={ eventId }
						options={ eventOptions }
						disabled={ ! sportKey || loadingEvents }
						onChange={ ( value ) => {
							const chosen = events.find(
								( e ) => e.id === value
							);
							setAttributes( {
								eventId: value,
								eventTitle: chosen ? chosen.title : '',
							} );
						} }
					/>
					<SelectControl
						label={ __( 'Market', 'odds-compare-kate' ) }
						value={ market || Object.keys( config.markets )[ 0 ] }
						options={ marketOptions }
						onChange={ ( value ) =>
							setAttributes( { market: value } )
						}
					/>
				</PanelBody>
				<PanelBody title={ __( 'Bookmakers', 'odds-compare-kate' ) }>
					<p>
						<Button
							variant="link"
							onClick={ () =>
								setAttributes( { bookmakers: [] } )
							}
						>
							{ __( 'All', 'odds-compare-kate' ) }
						</Button>
						{ ' · ' }
						<Button
							variant="link"
							onClick={ () =>
								setAttributes( {
									bookmakers: allBookmakers.length
										? [ allBookmakers[ 0 ].key ]
										: [],
								} )
							}
						>
							{ __( 'Only the first', 'odds-compare-kate' ) }
						</Button>
					</p>
					{ allBookmakers.map( ( row ) => (
						<CheckboxControl
							key={ row.key }
							label={ row.title }
							checked={ isChecked( row.key ) }
							onChange={ ( checked ) =>
								toggleBookmaker( row.key, checked )
							}
						/>
					) ) }
					{ config.settingsUrl && (
						<p>
							<a href={ config.settingsUrl }>
								{ __(
									'Manage the bookmaker list and links in settings.',
									'odds-compare-kate'
								) }
							</a>
						</p>
					) }
				</PanelBody>
				<PanelBody title={ __( 'Display', 'odds-compare-kate' ) }>
					<TextControl
						label={ __( 'Heading', 'odds-compare-kate' ) }
						value={ title }
						onChange={ ( value ) =>
							setAttributes( { title: value } )
						}
					/>
					<SelectControl
						label={ __( 'Odds format', 'odds-compare-kate' ) }
						value={ format }
						options={ formatOptions }
						onChange={ ( value ) =>
							setAttributes( { format: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Highlight the best price',
							'odds-compare-kate'
						) }
						checked={ highlightBest }
						onChange={ ( value ) =>
							setAttributes( { highlightBest: value } )
						}
					/>
					<ToggleControl
						label={ __(
							'Show when odds were updated',
							'odds-compare-kate'
						) }
						checked={ showUpdated }
						onChange={ ( value ) =>
							setAttributes( { showUpdated: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>

			{ eventId ? (
				<ServerSideRender
					block="odds-compare/comparison"
					attributes={ attributes }
				/>
			) : (
				<Placeholder
					icon="chart-bar"
					label={ __( 'Odds Comparison', 'odds-compare-kate' ) }
					instructions={ __(
						'Pick a competition and an event in the block settings on the right.',
						'odds-compare-kate'
					) }
				>
					{ eventTitle }
				</Placeholder>
			) }
		</div>
	);
}
