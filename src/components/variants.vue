<template>
  <section class="simplify-languages">
    <header v-if="hasVariants" class="k-field-header">
      <label class="k-label k-field-label">
        <span class="k-label-text">
          {{ $t("simplify.languages.headline") }}
        </span>
      </label>
    </header>

    <div class="k-collection">
      <div
        v-if="hasVariants"
        class="k-items k-list-items"
        data-layout="list"
        data-size="medium"
      >
        <div
          v-for="variant in variants"
          :key="variant.code"
          data-has-image="true"
          data-layout="list"
          class="k-item k-list-item"
        >
          <figure
            class="k-frame k-icon-frame k-item-image"
            style="--fit: cover; --ratio: auto; --back: var(--color-black)"
          >
            <svg
              aria-hidden="true"
              data-type="translate"
              class="k-icon"
              style="color: var(--color-gray)"
            >
              <use xlink:href="#icon-translate"></use>
            </svg>
          </figure>

          <div class="k-item-content">
            <h3
              :title="
                variant.enabled === false
                  ? variant.name + ' (' + $t('simplify.languages.paused') + ')'
                  : variant.name
              "
              class="k-item-title"
              style="display: flex; align-items: center"
            >
              <a
                :href="$panel.url('simplify/variants/' + variant.code)"
                class="k-link"
                style="
                  display: inline-flex;
                  align-items: center;
                  white-space: nowrap;
                  gap: 0.3rem;
                "
              >
                <span
                  v-if="variant.enabled === false"
                  :title="$t('simplify.languages.paused')"
                  style="
                    display: inline-flex;
                    align-items: center;
                    flex-shrink: 0;
                  "
                >
                  <k-icon
                    type="pause"
                    class="variant-paused-icon"
                    style="color: var(--color-gray-600)"
                  />
                </span>
                <span>{{ variant.name }}</span>
              </a>
            </h3>
            <span
              :title="
                variant.providerLabel || $t('simplify.provider.notConfigured')
              "
              class="k-item-info"
            >
              <span v-if="variant.providerLabel">{{
                variant.providerLabel
              }}</span>
              <span v-else class="missingProvider">{{
                $t("simplify.provider.notConfigured")
              }}</span>
            </span>
          </div>

          <nav class="k-item-options">
            <k-button
              icon="dots"
              class="k-item-options-button"
              @click="toggleOptions(variant.code)"
            />
            <k-dropdown-content
              :ref="dropdownRefKey(variant.code)"
              alignx="right"
            >
              <k-dropdown-item icon="edit" @click="openVariant(variant)">
                {{ $t("edit") }}
              </k-dropdown-item>
              <k-dropdown-item
                icon="sparkling"
                @click="openAssignProviderDialog(variant)"
              >
                {{ $t("simplify.provider.assign") }}
              </k-dropdown-item>
              <k-dropdown-item
                icon="cog"
                @click="$emit('open-settings', variant)"
              >
                {{ $t("settings") }}
              </k-dropdown-item>
              <hr />
              <k-dropdown-item icon="trash" @click="$emit('delete', variant)">
                {{ $t("delete") }}
              </k-dropdown-item>
            </k-dropdown-content>
          </nav>
        </div>
      </div>

      <k-empty v-else icon="translate" layout="cards">
        {{ $t("simplify.languages.empty") }}
      </k-empty>
    </div>
  </section>
</template>

<script>
export default {
  name: "VariantsTab",
  props: {
    variants: {
      type: Array,
      default: () => [],
    },
  },
  computed: {
    hasVariants() {
      return Array.isArray(this.variants) && this.variants.length > 0;
    },
  },
  methods: {
    dropdownRefKey(code) {
      return `options-${code}`;
    },
    getDropdownRef(code) {
      const ref = this.$refs[this.dropdownRefKey(code)];
      return Array.isArray(ref) ? ref[0] : ref;
    },
    toggleOptions(code) {
      const dropdown = this.getDropdownRef(code);
      if (dropdown && typeof dropdown.toggle === "function") {
        dropdown.toggle();
      }
    },
    openVariant(variant) {
      this.$panel.view.open(`simplify/variants/${variant.code}`);
    },
    openAssignProviderDialog(variant) {
      this.$emit("assign-provider", variant);
    },
  },
};
</script>
