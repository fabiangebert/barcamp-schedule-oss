
/**
 * Generates a deterministic color based on a string (user email, name, or guest_id).
 */
export function useUserColor() {
  const colors = [
    '#6b6899', // Pastel Navy (Requested)
    '#e07a74', // Muted Coral (Darker than #ffb3ae)
    '#bda832', // Muted Yellow (Darker than #fff2ac)
    '#5ca3a3', // Muted Aqua (Darker than #bdeaea)
    '#70707c', // Muted Gray Mid (Requested)
    '#8b7fb3', // Muted Violet
    '#6a9c81', // Muted Emerald
    '#648bbd', // Muted Blue
    '#c28359', // Muted Orange
    '#b86e8a', // Muted Rose
  ]

  // Brighter counterparts for the dark theme, where the mid-tones above
  // would sit too close to the dark card background to stay readable.
  const darkColors = [
    '#9e9ad0',
    '#ff9b93',
    '#e3ca57',
    '#7ecfcf',
    '#b0b0bc',
    '#bcaee6',
    '#84cb9f',
    '#8db6e6',
    '#e0a878',
    '#dd92ac',
  ]

  const indexFor = (id) => {
    if (!id) return 0

    // Simple hash function
    let hash = 0
    for (let i = 0; i < id.length; i++) {
      hash = id.charCodeAt(i) + ((hash << 5) - hash)
    }

    return Math.abs(hash) % colors.length
  }

  const getColor = (id, { dark = false } = {}) => {
    const palette = dark ? darkColors : colors
    return palette[indexFor(id)]
  }

  return {
    getColor
  }
}
