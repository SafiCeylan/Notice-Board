/**
 * Duyuru Panosu — derleme yapılandırması (Vue 3).
 *
 * Kendi config'imizi yazıyoruz (hazır @nextcloud/webpack-vue-config yerine);
 * böylece Vue 3 + @nextcloud/vue 9 zincirini kendimiz kontrol ediyoruz.
 *
 * ⚠️ Çıktı adı ile PHP tarafı bağlı:
 *   js/noticeboard-main.js  ←→  PageController: Util::addScript('noticeboard', 'noticeboard-main')
 * Ad değişirse sayfa sessizce boş açılır.
 */

const path = require('path')
const { VueLoaderPlugin } = require('vue-loader')
const TerserPlugin = require('terser-webpack-plugin')
const webpack = require('webpack')

const appName = 'noticeboard'
const isDev = process.env.NODE_ENV === 'development'

module.exports = {
	target: 'web',
	mode: isDev ? 'development' : 'production',

	// Üretimde kaynak haritası üretilmez — tüm kaynağı dağıtım paketine sızdırır.
	devtool: isDev ? 'eval-cheap-module-source-map' : false,

	// Her giriş ayrı bir dosya üretir ve PHP tarafında Util::addScript ile
	// adıyla yüklenir: main → noticeboard-main.js, admin → noticeboard-admin.js
	entry: {
		main: path.join(__dirname, 'src', 'main.js'),
		admin: path.join(__dirname, 'src', 'admin.js'),
		dashboard: path.join(__dirname, 'src', 'dashboard.js'),
	},

	output: {
		path: path.join(__dirname, 'js'),
		publicPath: `/apps/${appName}/js/`,
		// Giriş dosyasının adı SABİT kalmalı: PHP tarafı onu adıyla yüklüyor
		// (Util::addScript) ve Nextcloud ona sürüm sorgusu ekleyerek önbelleği kırıyor.
		filename: `${appName}-[name].js`,

		// ⚠️ Tembel yüklenen parçalara Nextcloud sürüm sorgusu EKLEMEZ; adları sabit
		// kalırsa tarayıcı eski parçayı sonsuza kadar önbellekten çalıştırır.
		// 23 Eyl 2026'da tam olarak bu oldu: dosya seçici düzeltmesi sunucuya gitti
		// ama kullanıcının tarayıcısı eski parçayı koşmaya devam etti (Hata #11).
		// İçerik damgası, içerik değişince adın da değişmesini garanti eder.
		chunkFilename: `${appName}-[name].[contenthash:8].js`,
		// Aynı sayfada başka NC uygulamaları da webpack çıktısı yükleyebilir;
		// global ad çakışırsa ikisi de bozulur.
		chunkLoadingGlobal: `webpackChunk_${appName}`,
		clean: true,
	},

	module: {
		rules: [
			{
				test: /\.vue$/,
				loader: 'vue-loader',
			},
			{
				test: /\.m?js$/,
				// @nextcloud/* paketleri modern sözdizimi içerir ama hedef
				// tarayıcılarımız onu zaten anlıyor; node_modules'ü Babel'den
				// geçirmek derlemeyi gereksiz yere dakikalara çıkarıyor.
				exclude: /node_modules/,
				use: 'babel-loader',
			},
			{
				test: /\.css$/,
				use: ['style-loader', 'css-loader'],
			},
			{
				test: /\.s[ac]ss$/,
				use: ['style-loader', 'css-loader', 'sass-loader'],
			},
			{
				// @nextcloud/vue bileşenlerinin ikonları ve yazı tipleri.
				test: /\.(png|jpe?g|gif|svg|woff2?|eot|ttf)$/,
				type: 'asset/inline',
			},
		],
	},

	resolve: {
		extensions: ['.js', '.vue'],
		fallback: {
			// @nextcloud/dialogs → @nextcloud/files → is-svg → sax zinciri Node'un
			// `stream` modülünü istiyor. Tarayıcıda bu yok ve bize gereken kod yolu
			// (SVG içerik denetimi) hiç çalışmıyor; polyfill eklemek yerine modülü
			// boş bırakıyoruz. webpack 5'in önerdiği yol bu.
			stream: false,
		},
	},

	plugins: [
		new VueLoaderPlugin(),
		// Vue 3 bu bayrakları bekler; tanımlanmazsa konsolda uyarı basar.
		new webpack.DefinePlugin({
			__VUE_OPTIONS_API__: JSON.stringify(true),
			__VUE_PROD_DEVTOOLS__: JSON.stringify(false),
			__VUE_PROD_HYDRATION_MISMATCH_DETAILS__: JSON.stringify(false),
		}),
	],

	optimization: {
		minimize: !isDev,
		minimizer: [
			new TerserPlugin({
				extractComments: true,
				terserOptions: {
					format: { comments: false },
				},
			}),
		],
	},

	performance: {
		// Tek parça SPA; webpack'in 244 KB uyarısı burada gürültüden ibaret.
		hints: false,
	},
}
