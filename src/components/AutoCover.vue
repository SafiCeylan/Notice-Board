<!--
  Kapaksız duyurunun otomatik kapağı: kategoriden türeyen renk + kategoriye
  uygun ikon (+ geniş çerçevede kategori adı). Kurallar src/cover/autoCover.js.

  Vitrin slaydı, kart ve liste küçük resmi aynı bileşeni kullanır; böylece bir
  duyuru her yerde aynı renkle tanınır.
-->
<template>
	<span class="nb-auto"
		:class="{ 'nb-auto--compact': compact }"
		:style="{ backgroundImage: `linear-gradient(135deg, ${cover.from}, ${cover.to})` }"
		aria-hidden="true">
		<component :is="iconComponent" :size="compact ? iconSize : 44" class="nb-auto__icon" />
		<span v-if="!compact && cover.label" class="nb-auto__label">{{ cover.label }}</span>
	</span>
</template>

<script>
import AccountGroup from 'vue-material-design-icons/AccountGroup.vue'
import AlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import Bullhorn from 'vue-material-design-icons/Bullhorn.vue'
import Bus from 'vue-material-design-icons/Bus.vue'
import CalendarStar from 'vue-material-design-icons/CalendarStar.vue'
import CurrencyUsd from 'vue-material-design-icons/CurrencyUsd.vue'
import HeartPulse from 'vue-material-design-icons/HeartPulse.vue'
import Laptop from 'vue-material-design-icons/Laptop.vue'
import PartyPopper from 'vue-material-design-icons/PartyPopper.vue'
import School from 'vue-material-design-icons/School.vue'
import ShieldAlert from 'vue-material-design-icons/ShieldAlert.vue'
import SilverwareForkKnife from 'vue-material-design-icons/SilverwareForkKnife.vue'
import Wrench from 'vue-material-design-icons/Wrench.vue'

import { autoCover } from '../cover/autoCover.js'

const ICONS = {
	alert: AlertCircle,
	bullhorn: Bullhorn,
	bus: Bus,
	calendar: CalendarStar,
	food: SilverwareForkKnife,
	health: HeartPulse,
	laptop: Laptop,
	money: CurrencyUsd,
	party: PartyPopper,
	people: AccountGroup,
	school: School,
	shield: ShieldAlert,
	wrench: Wrench,
}

export default {
	name: 'AutoCover',

	props: {
		announcement: {
			type: Object,
			required: true,
		},
		/** Küçük resim (kart/liste): yalnız ikon, yazı yok. */
		compact: {
			type: Boolean,
			default: false,
		},
		iconSize: {
			type: Number,
			default: 24,
		},
	},

	computed: {
		cover() {
			return autoCover(this.announcement)
		},

		iconComponent() {
			return ICONS[this.cover.icon] ?? Bullhorn
		},
	},
}
</script>

<style scoped>
.nb-auto {
	position: relative;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: center;
	justify-content: center;
	width: 100%;
	height: 100%;
	overflow: hidden;
	color: #fff;
}

/* Hafif desen: düz renk "boş kalmış" gibi görünmesin. */
.nb-auto::before {
	position: absolute;
	inset: 0;
	background-image: radial-gradient(circle at 85% 15%, rgba(255, 255, 255, 0.22) 0, transparent 45%),
		radial-gradient(circle at 10% 90%, rgba(0, 0, 0, 0.18) 0, transparent 50%);
	content: '';
}

.nb-auto__icon {
	position: relative;
	opacity: 0.95;
	filter: drop-shadow(0 2px 6px rgba(0, 0, 0, 0.25));
}

.nb-auto__label {
	position: relative;
	max-width: 85%;
	overflow: hidden;
	font-size: 0.95em;
	font-weight: 600;
	letter-spacing: 0.02em;
	text-overflow: ellipsis;
	white-space: nowrap;
	text-shadow: 0 1px 3px rgba(0, 0, 0, 0.3);
}
</style>
