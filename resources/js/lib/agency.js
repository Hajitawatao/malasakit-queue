// One place for department colours, logos and plain descriptions (used by every screen).
export const AGENCY = {
  DOH: {
    color: '#0f7b6c',
    logo: '/logos/doh.png',
    en: 'Medical and hospital bill assistance',
    tl: 'Tulong medikal at sa bayarin sa ospital',
  },
  DSWD: {
    color: '#2350b0',
    logo: '/logos/dswd.png',
    en: 'Social welfare assistance',
    tl: 'Tulong mula sa DSWD',
  },
  PHIC: {
    color: '#4e9a2f',
    logo: '/logos/philhealth.png',
    short: 'PH',
    en: 'PhilHealth benefits and membership',
    tl: 'Benepisyo at membership sa PhilHealth',
  },
}

export const agencyColor = (code) => AGENCY[code]?.color ?? '#3b5b7e'
export const agencyLogo = (code) => AGENCY[code]?.logo ?? null

export const LANES = {
  fast: { label: 'Fast lane', tl: 'Senior citizen, PWD, buntis', letter: 'F' },
  priority: { label: 'Priority', tl: 'May referral o endorsement', letter: 'P' },
  regular: { label: 'Regular', tl: 'Lahat ng iba pa', letter: 'R' },
}

export const laneOf = (code) => ({ F: 'fast', P: 'priority' })[code?.[0]] ?? 'regular'

/** Hide a broken logo image (e.g. PhilHealth logo not added yet). */
export const hideOnError = (e) => {
  e.target.style.display = 'none'
}
