<template>
  <section class="simplify-providers">
    <header v-if="hasProviders" class="k-section-header">
      <h2
        class="k-label k-section-label"
        :title="$t('simplify.providers.headline')"
      >
        <span class="k-label-text">{{
          $t("simplify.providers.headline")
        }}</span>
      </h2>
    </header>

    <div class="k-collection">
      <div
        v-if="hasProviders"
        class="k-items k-list-items"
        data-layout="list"
        data-size="medium"
      >
        <div
          v-for="provider in providersList"
          :key="provider.id"
          data-has-image="true"
          data-layout="list"
          class="k-item k-list-item"
        >
          <figure
            v-if="provider.icon"
            class="k-frame k-icon-frame k-item-image"
            style="--fit: cover; --ratio: auto; --back: var(--color-black)"
          >
            <svg
              aria-hidden="true"
              :data-type="provider.icon"
              class="k-icon"
              style="color: var(--color-gray)"
            >
              <use :xlink:href="'#icon-' + provider.icon"></use>
            </svg>
          </figure>

          <div class="k-item-content">
            <h3 :title="provider.name" class="k-item-title">
              <a
                :href="$panel.url('simplify/providers/' + provider.id)"
                class="k-link"
              >
                <span>{{ provider.name }}</span>
              </a>
            </h3>
            <p class="k-item-info">
              {{ provider.modelCount }}
              {{
                provider.modelCount === 1
                  ? $t("simplify.models.count.singular")
                  : $t("simplify.models.count.plural")
              }}
            </p>
          </div>
        </div>
      </div>

      <k-empty v-else icon="sparkling" layout="cards">
        {{ $t("simplify.providers.empty") }}
      </k-empty>
    </div>
  </section>
</template>

<script>
export default {
  name: "ProvidersTab",
  props: {
    providers: {
      type: Object,
      default: () => ({}),
    },
  },
  computed: {
    providersList() {
      return Object.entries(this.providers).map(([id, config]) => ({
        id,
        name: config.displayName || id,
        icon: config.icon || "sparkling",
        modelCount: config.modelCount || 0,
      }));
    },
    hasProviders() {
      return this.providersList.length > 0;
    },
  },
  methods: {
    getProviderName(providerId) {
      // Provider metadata is enriched by backend via ProviderHelper
      const provider = this.providersList.find((p) => p.id === providerId);
      return provider?.name || providerId;
    },
    getProviderIcon(providerId) {
      // Icons are enriched by backend via ProviderHelper
      const provider = this.providersList.find((p) => p.id === providerId);
      return provider?.icon || "sparkling";
    },
  },
};
</script>
