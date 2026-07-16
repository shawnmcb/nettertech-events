/**
 * WordPress Scripts Webpack Configuration
 *
 * Extends the default @wordpress/scripts webpack config to support
 * multiple Gutenberg block entry points with JSX transpilation.
 *
 * IMPORTANT: output.path is the plugin root because block.json files
 * reference built assets at ./build/index.js relative to each block
 * directory. CleanWebpackPlugin is removed to prevent it from deleting
 * the entire plugin when it cleans the output directory.
 */

const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaultConfig,
	entry: {
		'blocks/calendar/build/index': path.resolve(
			__dirname,
			'blocks/calendar/src/index.jsx'
		),
		'blocks/carousel/build/index': path.resolve(
			__dirname,
			'blocks/carousel/src/index.jsx'
		),
		'blocks/event-grid/build/index': path.resolve(
			__dirname,
			'blocks/event-grid/src/index.jsx'
		),
	},
	output: {
		...defaultConfig.output,
		path: path.resolve( __dirname ),
		filename: '[name].js',
		clean: false,
	},
	plugins: defaultConfig.plugins.filter(
		( plugin ) => plugin.constructor.name !== 'CleanWebpackPlugin'
	),
};
