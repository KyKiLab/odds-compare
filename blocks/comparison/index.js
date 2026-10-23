import { registerBlockType } from '@wordpress/blocks';

import metadata from './block.json';
import Edit from './edit';
import './style.scss';
import './editor.scss';

registerBlockType( metadata.name, {
	edit: Edit,
	// Dynamic block: the server renders from live cache, nothing is stored but attributes.
	save: () => null,
} );
