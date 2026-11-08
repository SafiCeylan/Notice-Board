module.exports = {
	presets: [
		['@babel/preset-env', {
			// browserslist (package.json) hedeflerini kullanır; polyfill enjekte etmez.
			bugfixes: true,
		}],
	],
}
