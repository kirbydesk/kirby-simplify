<template>
  <section class="simplify-pages-tab">
    <div v-if="stats && stats.length" class="k-field">
      <header class="k-field-header">
        <label class="k-label k-field-label">
          <span class="k-label-text">
            {{ $t("simplify.pages.stats.label") }}
          </span>
        </label>
      </header>
      <dl data-size="large" class="k-stats" style="margin-bottom: 2.5rem">
        <div
          class="k-stat"
          v-for="stat in stats"
          :key="stat.label"
          :data-theme="stat.theme"
        >
          <dt class="k-stat-label">
            <svg aria-hidden="true" :data-type="stat.icon" class="k-icon">
              <use :xlink:href="`#icon-${stat.icon}`"></use>
            </svg>
            {{ stat.type }}
          </dt>
          <dd class="k-stat-value" v-html="stat.value"></dd>
          <dd class="k-stat-info">{{ stat.label }}</dd>
        </div>
      </dl>
    </div>

    <k-grid variant="fields">
      <k-column width="2/3">
        <header class="k-field-header">
          <label class="k-label k-field-label">
            <span class="k-label-text">
              {{ $t("simplify.pages.list.label") }}
              <template v-if="pages.length > 0">
                ({{ filteredPages.length }})
              </template>
            </span>
          </label>
          <div
            v-if="pages.length > 0"
            style="display: flex; gap: var(--spacing-2); align-items: center"
          >
            <k-input
              v-if="searching"
              ref="search"
              v-model="searchQuery"
              :placeholder="$t('filter') + ' …'"
              type="text"
              class="k-models-section-search k-input"
              style="
                min-height: auto;
                height: var(--height-xs);
                margin-bottom: 0;
                width: 200px;
              "
              @keydown.esc="toggleSearch(true)"
            />
            <button
              data-has-icon="true"
              data-size="xs"
              data-variant="filled"
              type="button"
              class="k-button"
              :title="searching ? $t('close') : $t('filter')"
              @click="toggleSearch"
            >
              <span class="k-button-icon">
                <svg
                  aria-hidden="true"
                  :data-type="searching ? 'cancel' : 'filter'"
                  class="k-icon"
                >
                  <use
                    :xlink:href="searching ? '#icon-cancel' : '#icon-filter'"
                  ></use>
                </svg>
              </span>
            </button>
            <button
              data-has-icon="true"
              data-size="xs"
              data-variant="filled"
              type="button"
              class="k-button"
              :title="$t('settings')"
              @click="$refs.pagesSettingsDropdown.toggle()"
            >
              <span class="k-button-icon">
                <svg aria-hidden="true" data-type="cog" class="k-icon">
                  <use xlink:href="#icon-cog"></use>
                </svg>
              </span>
            </button>
          </div>
        </header>
        <k-dropdown-content
          v-if="pages.length > 0"
          ref="pagesSettingsDropdown"
          :options="pagesSettingsDropdownOptions"
          align-x="end"
        />

        <k-empty
          v-if="pages.length === 0"
          :text="$t('simplify.pages.empty')"
          icon="page"
          layout="cards"
        />

        <k-empty
          v-else-if="filteredPages.length === 0"
          :text="$t('simplify.pages.empty')"
          icon="page"
          layout="cards"
        />

        <template v-else>
          <k-table
            :columns="columns"
            :rows="paginatedPages"
            @header="onHeader"
            @click.native="onTableClick"
          >
            <template #header="{ columnIndex, label }">
              <span>
                {{ label }}
                <k-icon
                  v-if="columnIndex === sortBy"
                  :type="sortDirection === 'asc' ? 'angle-up' : 'angle-down'"
                />
              </span>
            </template>
            <template #options="{ row }">
              <k-options-dropdown :options="getPageOptions(row)" />
            </template>
          </k-table>

          <footer class="k-bar k-collection-footer">
            <k-pagination
              v-bind="pagination"
              :details="true"
              :total="filteredPages.length"
              @paginate="onPaginate"
            />
          </footer>
        </template>
      </k-column>

      <k-column width="1/3">
        <k-fieldset :fields="optOutTemplatesField" v-model="localOptOutData" />
      </k-column>
    </k-grid>
  </section>
</template>

<script>
export default {
  name: "PagesTab",
  props: {
    stats: {
      type: Array,
      default: () => [],
    },
    pages: {
      type: Array,
      default: () => [],
    },
    getPageOptions: {
      type: Function,
      required: true,
    },
    availableTemplates: {
      type: Array,
      default: () => [],
    },
    optOutValue: {
      type: Object,
      default: () => ({}),
    },
    variantCode: {
      type: String,
      required: true,
    },
    isEnabled: {
      type: Boolean,
      default: true,
    },
  },
  data() {
    return {
      searching: false,
      searchQuery: "",
      sortBy: "title",
      sortDirection: "asc",
      pagination: {
        page: 1,
        limit: 100,
      },
    };
  },
  computed: {
    optOutTemplatesField() {
      const templateOptions = this.availableTemplates.map((template) => {
        const countLabel =
          template.count === 1
            ? this.$t("simplify.pages.count.singular")
            : this.$t("simplify.pages.count.plural");

        return {
          value: template.value,
          text: `<span style="display: block;">${template.title}</span><span class="k-text" style="opacity: 0.6; font-size: 0.875em;">${template.count} ${countLabel}</span>`,
        };
      });

      const selectedCount = (this.optOutValue?.opt_out_templates || []).length;

      return {
        opt_out_templates: {
          label:
            this.$t("simplify.privacy.optout.templates.label") +
            ` (${selectedCount}/${templateOptions.length})`,
          type: "checkboxes",
          options: templateOptions,
          counter: false,
          help: this.$t("simplify.privacy.optout.templates.help"),
        },
      };
    },
    localOptOutData: {
      get() {
        return this.optOutValue;
      },
      set(val) {
        // Emit auto-save FIRST (synchronously) so parent can update saved state immediately
        this.$emit("auto-save-optout", val);

        // Then emit update event
        this.$emit("update:optOutValue", val);
      },
    },
    columns() {
      return {
        title: {
          label: this.$t("simplify.pages.table.title"),
          type: "html",
          width: "3/4",
          mobile: true,
        },
        template: {
          label: this.$t("simplify.pages.table.template"),
          type: "html",
          width: "1/4",
        },
        mode: {
          label: this.$t("simplify.pages.table.mode"),
          type: "html",
          width: "50px",
        },
      };
    },
    filteredPages() {
      if (!this.searchQuery) {
        return this.pages;
      }
      const query = this.searchQuery.toLowerCase();
      return this.pages.filter((page) => {
        return (
          page.title.toLowerCase().includes(query) ||
          page.template.toLowerCase().includes(query) ||
          (page.mode && page.mode.toLowerCase().includes(query))
        );
      });
    },
    formattedPages() {
      // Create a Map of template values to titles for fast lookup
      const templateMap = new Map(
        this.availableTemplates.map((t) => [t.value, t.title])
      );

      return this.filteredPages.map((page) => {
        // Check if the page's template exists in available templates
        const templateLabel = templateMap.get(page.template);
        const templateDisplay = templateLabel
          ? `<span class="simplifyTemplate">${templateLabel}</span>`
          : ``;

        return {
          ...page,
          rawTitle: page.title, // Store original title for sorting
          title: this.getTitleWithStatus(page),
          template: templateDisplay,
          mode: this.getModeDisplay(page),
        };
      });
    },
    sortedPages() {
      const pages = [...this.formattedPages];

      if (!this.sortBy) {
        return pages;
      }

      return pages.sort((a, b) => {
        // Always use rawTitle for title sorting (plain text, case-insensitive)
        let aVal =
          this.sortBy === "title"
            ? (a.rawTitle || "").toLowerCase()
            : a[this.sortBy] || "";
        let bVal =
          this.sortBy === "title"
            ? (b.rawTitle || "").toLowerCase()
            : b[this.sortBy] || "";

        if (this.sortDirection === "asc") {
          return aVal.localeCompare(bVal);
        } else {
          return bVal.localeCompare(aVal);
        }
      });
    },
    paginatedPages() {
      const start = (this.pagination.page - 1) * this.pagination.limit;
      const end = this.pagination.limit * this.pagination.page;
      return this.sortedPages.slice(start, end);
    },
    missingPagesCount() {
      const optOutTemplates = this.optOutValue?.opt_out_templates || [];
      return this.pages.filter((page) => {
        if (page.mode === "off") return false;
        if (page.template && optOutTemplates.includes(page.template))
          return false;
        if (page.hasTranslation) return false;
        return true;
      }).length;
    },
    translatedPagesCount() {
      return this.pages.filter((page) => page.hasTranslation).length;
    },
    pagesSettingsDropdownOptions() {
      const self = this;
      const options = [];

      // Add translation options (disabled when paused)
      options.push(
        {
          icon: "sparkling",
          text: this.$t("simplify.pages.translateMissing"),
          disabled: !this.isEnabled || this.missingPagesCount === 0,
          click() {
            self.translateMissingPages();
          },
        },
        {
          icon: "sparkling",
          text: this.$t("simplify.pages.translateAll"),
          disabled: !this.isEnabled,
          click() {
            self.translateAllPages();
          },
        },
        {
          icon: "pause",
          text: this.isEnabled
            ? this.$t("simplify.languages.pause")
            : this.$t("simplify.languages.resume"),
          click() {
            self.$emit("toggle-enabled");
          },
        },
        "-"
      );

      return options.concat([
        {
          icon: "trash",
          text: this.$t("simplify.pages.deleteAll"),
          disabled: this.translatedPagesCount === 0,
          click() {
            self.deleteAllTranslations();
          },
        },
      ]);
    },
  },
  methods: {
    getTitleWithStatus(page) {
      const status = page.status || "draft";
      const statusTheme =
        {
          draft: "negative-icon",
          unlisted: "info-icon",
          listed: "positive-icon",
        }[status] || "info-icon";

      const statusText = this.$t("page.status");
      const statusLabel =
        {
          draft: this.$t("page.status.draft"),
          unlisted: this.$t("page.status.unlisted"),
          listed: this.$t("page.status.listed"),
        }[status] || this.$t("page.status.draft");

      // Translation status icon (only show if translated)
      const translatedTitle = this.$t("simplify.pages.translationExists");
      const translatedIcon = page.hasTranslation
        ? ` <span title="${translatedTitle}" style="display: inline-flex; align-items: center; vertical-align: middle;"><svg aria-hidden="true" data-type="check" class="k-icon" style="color: var(--color-positive); width: 14px; height: 14px;"><use xlink:href="#icon-check"></use></svg></span>`
        : "";

      return `<span style="display:flex;align-items:flex-start;gap:var(--spacing-2);">
        <button data-has-icon="true" aria-label="${statusText}: ${statusLabel}" data-size="xs" data-theme="${statusTheme}" title="${statusText}: ${statusLabel}" type="button" class="k-button" style="--icon-size: 15px; flex-shrink: 0;">
          <span class="k-button-icon">
            <svg aria-hidden="true" data-type="status-${status}" class="k-icon">
              <use xlink:href="#icon-status-${status}"></use>
            </svg>
          </span>
        </button>
        <span>${page.title}${translatedIcon}</span>
      </span>`;
    },
    modeLabel(mode) {
      const value = mode || "unknown";
      const text = this.$t(`simplify.pages.mode.${value}`) || value;
      return text.charAt(0).toUpperCase();
    },
    getModeDisplay(page) {
      const mode = page.mode || "unknown";

      // Show only pause icon (without badge) if mode is "auto" and variant is paused
      if (mode === "auto" && !this.isEnabled) {
        return `<span data-page-uuid="${
          page.uuid
        }" style="cursor: pointer; display: flex; align-items: center; justify-content: center; width: 100%; color: var(--color-gray-600);" title="${this.$t(
          "simplify.pages.changeModus"
        )}"><svg aria-hidden="true" data-type="pause" class="k-icon" style="width: 18px; height: 18px;"><use xlink:href="#icon-pause"></use></svg></span>`;
      }

      return `<span class="variant-mode variant-mode--${mode}" data-page-uuid="${
        page.uuid
      }" style="cursor: pointer; display: inline-flex; align-items: center; justify-content: center;" title="${this.$t(
        "simplify.pages.changeModus"
      )}">${this.modeLabel(mode)}</span>`;
    },
    async toggleSearch(close = false) {
      if (close && this.searchQuery) {
        this.searchQuery = "";
        return;
      }

      this.searching = !this.searching;

      if (this.searching) {
        await this.$nextTick();
        this.$refs.search?.focus();
      } else {
        this.searchQuery = "";
      }
    },
    onHeader({ columnIndex }) {
      if (this.sortBy === columnIndex) {
        // Toggle between asc and desc
        this.sortDirection = this.sortDirection === "asc" ? "desc" : "asc";
      } else {
        this.sortBy = columnIndex;
        this.sortDirection = "asc";
      }
      this.pagination.page = 1;
    },
    onPaginate({ page }) {
      this.pagination.page = page;
    },
    onTableClick(event) {
      // Check if clicked element is a mode badge or has data-page-uuid
      const modeBadge =
        event.target.closest(".variant-mode") ||
        event.target.closest("[data-page-uuid]");
      if (modeBadge) {
        const pageUuid = modeBadge.getAttribute("data-page-uuid");
        // Find the original page object
        const page = this.pages.find((p) => p.uuid === pageUuid);
        if (page) {
          this.$emit("change-mode", page);
        }
      }
    },
    async translateMissingPages() {
      const count = this.missingPagesCount;

      if (count === 0) {
        return;
      }

      // Show dialog asking for confirmation
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.pages.translateMissing.confirm", { count }),
          submitButton: this.$t("simplify.pages.translateMissing.submit", {
            count,
          }),
          cancelButton: this.$t("cancel"),
          icon: "sparkling",
          theme: "positive",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                "simplify/pages/translate-missing",
                {
                  variantCode: this.variantCode,
                }
              );

              if (response.success) {
                this.$panel.notification.success(
                  this.$t("simplify.pages.translateMissingStarted", {
                    count: response.count,
                  })
                );
                this.$emit("jobs-added");
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.pages.queueAddError")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.pages.queueAddError") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    translateAllPages() {
      const totalPages = this.pages.length;

      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.pages.translateAll.confirm", {
            count: totalPages,
          }),
          submitButton: this.$t("simplify.pages.translateAll.submit", {
            count: totalPages,
          }),
          cancelButton: this.$t("cancel"),
          icon: "sparkling",
          theme: "positive",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                "simplify/pages/translate-all",
                {
                  variantCode: this.variantCode,
                }
              );

              if (response.success) {
                this.$panel.notification.success(
                  this.$t("simplify.pages.translateAllStarted", {
                    count: response.count || totalPages,
                  })
                );
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.pages.queueAddError")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.pages.queueAddError") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    deleteAllTranslations() {
      const totalPages = this.translatedPagesCount;

      if (totalPages === 0) {
        return;
      }

      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.pages.deleteAll.confirm", {
            count: totalPages,
          }),
          submitButton: this.$t("delete"),
          cancelButton: this.$t("cancel"),
          icon: "trash",
          theme: "negative",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post(
                "simplify/pages/delete-all-translations",
                {
                  variantCode: this.variantCode,
                }
              );

              if (response.success) {
                // Reload page to reflect changes
                this.$emit("reload");
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.pages.deleteAllError")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.pages.deleteAllError") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
  },
};
</script>
<style>
.simplify-pages-tab {
  display: flex;
  flex-direction: column;
  gap: var(--spacing-6);

  .k-table-column[data-column-id="mode"] {
    cursor: pointer;
  }
  /* Truncate template names with ellipsis */
  .simplifyTemplate {
    display: inline-block;
    max-width: 100%;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: middle;
  }
  /* Remove link behavior from checkboxes field label */
  .k-checkboxes-field > .k-field-header > .k-field-label {
    pointer-events: none;
    cursor: default;
  }
  /* Remove pointer cursor from pages list label */
  > .k-grid > .k-column > .k-field-header > .k-field-label {
    cursor: default;
  }
}
</style>
