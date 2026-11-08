/**
 * Kapağa yazı bindirme ("SON GÜN 15 EKİM" gibi kısa bir şerit).
 *
 * ⚠️ TEK çizim yolu: düzenleyici sahnesindeki katman, sağdaki canlı önizleme ve
 * kaydedilen görsel hepsi `drawOverlay` ile çizilir. Ölçüler görselin
 * boyutuna ORANLA hesaplanır; bu yüzden 300 px'lik sahnede de 1920 px'lik
 * çıktıda da yazı aynı yerde ve aynı oranda durur.
 *
 * Yerleşim (`layoutOverlay`) saf fonksiyondur, ölçme işlevi dışarıdan verilir —
 * düğümde sahte ölçüyle test edilir (tests/js/textOverlay.test.mjs).
 */

export const MAX_OVERLAY_LENGTH = 80
export const MAX_LINES = 3

export const OVERLAY_POSITIONS = ['top', 'center', 'bottom']
export const OVERLAY_STYLES = ['band', 'shadow', 'light']
/** Yazı boyutu: görselin "16:9 eşdeğeri" yüksekliğine oran. */
export const OVERLAY_SIZES = { small: 0.07, medium: 0.1, large: 0.14 }

export const EMPTY_OVERLAY = Object.freeze({ text: '', position: 'bottom', style: 'band', size: 'medium' })

const LINE_HEIGHT = 1.2
/** Yazının kenarlardan uzaklığı ve şeridin iç boşluğu, yazı boyutuna oran. */
const PADDING = 0.6

const STYLE_COLORS = {
	band: { text: '#ffffff', band: 'rgba(0, 0, 0, 0.55)', shadow: null },
	shadow: { text: '#ffffff', band: null, shadow: 'rgba(0, 0, 0, 0.75)' },
	light: { text: '#1a1a1a', band: 'rgba(255, 255, 255, 0.85)', shadow: null },
}

export const hasOverlay = overlay => Boolean(overlay && String(overlay.text ?? '').trim())

/**
 * Metni en fazla `maxLines` satıra böler; sığmayan son satırın sonuna "…" konur.
 * Tek başına sığmayan uzun kelime harf harf kesilir.
 *
 * @param {string} text
 * @param {number} maxWidth
 * @param {(s: string) => number} measure
 */
export const wrapLines = (text, maxWidth, measure, maxLines = MAX_LINES) => {
	const words = String(text).replace(/\s+/g, ' ').trim().split(' ').filter(Boolean)
	const lines = []
	let current = ''
	let overflow = false

	const fitWord = word => {
		let piece = word
		while (piece.length > 1 && measure(piece) > maxWidth) {
			piece = piece.slice(0, -1)
		}
		return piece
	}

	for (let i = 0; i < words.length; i++) {
		const candidate = current ? `${current} ${words[i]}` : words[i]
		if (measure(candidate) <= maxWidth) {
			current = candidate
			continue
		}
		if (current) {
			lines.push(current)
			current = ''
			i--
		} else {
			// Tek kelime satıra sığmıyor: kesilir, kalanı bir sonraki satıra.
			const piece = fitWord(words[i])
			lines.push(piece)
			const rest = words[i].slice(piece.length)
			if (rest) {
				words.splice(i + 1, 0, rest)
			}
		}
		if (lines.length === maxLines) {
			overflow = i < words.length - 1 || current !== ''
			break
		}
	}
	if (current && lines.length < maxLines) {
		lines.push(current)
	}

	if (overflow && lines.length) {
		let last = lines[lines.length - 1]
		while (last.length > 1 && measure(`${last}…`) > maxWidth) {
			last = last.slice(0, -1)
		}
		lines[lines.length - 1] = `${last.trimEnd()}…`
	}

	return lines
}

/**
 * @param {{width: number, height: number}} size
 * @param {typeof EMPTY_OVERLAY} overlay
 * @param {(s: string, fontSize: number) => number} measure yazı genişliği
 */
export const layoutOverlay = (size, overlay, measure) => {
	if (!hasOverlay(overlay) || !size.width || !size.height) {
		return null
	}

	// Kare/dikey kapakta yazı dev olmasın: ölçü 16:9 eşdeğeri yüksekliktir.
	const base = Math.min(size.height, size.width * 9 / 16)
	// ⚠️ Yuvarlanmaz: boşluklar ayrı ayrı yuvarlanınca küçük sahnede yazı, büyük
	// çıktıdakine göre birkaç piksel kayıyordu (test yakaladı). Tuval kesirli pikseli çizer.
	const fontSize = Math.max(8, base * (OVERLAY_SIZES[overlay.size] ?? OVERLAY_SIZES.medium))
	const pad = fontSize * PADDING
	const lineHeight = fontSize * LINE_HEIGHT
	const maxWidth = size.width - pad * 4

	const lines = wrapLines(overlay.text, maxWidth, s => measure(s, fontSize))
	const blockHeight = lines.length * lineHeight + pad * 2

	let top
	if (overlay.position === 'top') {
		top = 0
	} else if (overlay.position === 'center') {
		top = (size.height - blockHeight) / 2
	} else {
		top = size.height - blockHeight
	}

	const colors = STYLE_COLORS[overlay.style] ?? STYLE_COLORS.band

	return {
		fontSize,
		lineHeight,
		lines,
		colors,
		// Şerit tam genişlik: kenarda "yapıştırılmış etiket" gibi durmasın.
		band: colors.band ? { x: 0, y: top, width: size.width, height: blockHeight } : null,
		// Satırların dikey ORTASI (textBaseline = 'middle').
		lineCenters: lines.map((_, i) => top + pad + i * lineHeight + lineHeight / 2),
		centerX: size.width / 2,
	}
}

export const overlayFont = (fontSize, family) => `700 ${fontSize}px ${family || 'sans-serif'}`

/** Tuvale çizer. `family` sayfanın yazı tipi (Nextcloud temasıyla aynı görünsün). */
export const drawOverlay = (ctx, width, height, overlay, family) => {
	const layout = layoutOverlay({ width, height }, overlay, (s, fontSize) => {
		ctx.font = overlayFont(fontSize, family)
		return ctx.measureText(s).width
	})
	if (!layout) {
		return
	}

	ctx.save()
	if (layout.band) {
		ctx.fillStyle = layout.colors.band
		ctx.fillRect(layout.band.x, layout.band.y, layout.band.width, layout.band.height)
	}
	ctx.font = overlayFont(layout.fontSize, family)
	ctx.textAlign = 'center'
	ctx.textBaseline = 'middle'
	ctx.fillStyle = layout.colors.text
	if (layout.colors.shadow) {
		ctx.shadowColor = layout.colors.shadow
		ctx.shadowBlur = layout.fontSize * 0.35
		ctx.shadowOffsetY = Math.max(1, layout.fontSize * 0.06)
	}
	layout.lines.forEach((line, i) => ctx.fillText(line, layout.centerX, layout.lineCenters[i]))
	ctx.restore()
}
