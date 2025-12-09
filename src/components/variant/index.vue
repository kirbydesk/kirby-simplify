<template>
  <k-panel-inside>
    <k-view class="simplify-variant-detail-view">
      <k-header :editable="true" @edit="openEditDialog">
        {{ variant.name }}
        <template #buttons>
          <k-button-group v-if="hasAnyChanges" layout="collapsed">
            <k-button
              icon="undo"
              theme="notice"
              variant="filled"
              size="sm"
              @click="discardChanges"
            >
              {{ $t("discard") }}
            </k-button>

            <k-button
              icon="check"
              theme="notice"
              variant="filled"
              size="sm"
              @click="saveAll"
            >
              {{ $t("save") }}
            </k-button>
          </k-button-group>
        </template>
      </k-header>

      <nav v-if="ruleData" class="k-tabs">
        <k-link
          v-for="tabItem in tabs"
          :key="tabItem.name"
          :to="tabItem.link"
          :title="tabItem.label"
          :aria-label="tabItem.label"
          :aria-current="tab === tabItem.name ? 'true' : undefined"
          data-variant="dimmed"
          data-has-text="true"
          class="k-tabs-button k-button"
        >
          <span class="k-button-icon">
            <k-icon v-if="tabItem.icon" :type="tabItem.icon" />
          </span>
          <span class="k-button-text">{{ tabItem.label }}</span>
          <span
            v-if="tabItem.badge > 0"
            data-theme="notice"
            class="k-button-badge"
          >
            {{ tabItem.badge }}
          </span>
        </k-link>
      </nav>

      <pages-tab
        v-if="ruleData && tab === 'pages'"
        :stats="pagesStats"
        :pages="filteredPages"
        :get-page-options="getPageOptions"
        :available-templates="availableTemplates"
        :opt-out-value="{ opt_out_templates: formData.opt_out_templates }"
        :variant-code="variant.code"
        :is-enabled="formData.enabled !== false"
        @update:optOutValue="handleOptOutUpdate"
        @auto-save-optout="autoSaveOptOut"
        @change-mode="openStatusDialog"
        @reload="reloadPage"
        @jobs-added="loadReportsCounts"
        @toggle-enabled="toggleEnabled"
      />

      <prompt-tab
        v-if="ruleData && tab === 'prompt'"
        :value="formData.ai_system_prompt"
        :saved="saved"
        :defaults="defaults"
        @update:value="(val) => (formData.ai_system_prompt = val)"
        @reset="resetPrompt"
      />

      <reports-tab
        ref="reportsTab"
        v-if="ruleData && tab === 'reports'"
        :variant-code="variant.code"
        :all-pages="allPages"
        :queue-count="queueCount"
        :cache-count="cacheCount"
        :reports-count="reportsCount"
        @reports-cleared="loadReportsCounts"
        @clear-queue="handleClearQueue"
        @clear-cache="handleClearCache"
      />

      <privacy-tab
        v-if="ruleData && tab === 'privacy'"
        :opt-out-fields="optOutFields"
        :opt-out-value="formData"
        :has-custom-opt-out="hasCustomOptOut"
        @update:optOutValue="(val) => Object.assign(formData, val)"
        @reset-optout="resetOptOutFields"
        :fields-fields="fieldInstructionFields"
        :fields-value="formData"
        :has-custom-field-instructions="hasCustomFieldInstructions"
        @update:fieldsValue="handleFieldsValueUpdate"
        @reset-fields="resetFieldInstructions"
        :masking-fields="maskingFields"
        :masking-value="formData"
        @update:maskingValue="(val) => Object.assign(formData, val)"
      />

      <k-box v-if="!ruleData" theme="notice">
        <k-text>{{ $t("simplify.rules.configMissing") }}</k-text>
      </k-box>
    </k-view>
  </k-panel-inside>
</template>

<script>
import PagesTab from "./pages.vue";
import PromptTab from "./prompt.vue";
import PrivacyTab from "./privacy.vue";
import ReportsTab from "./reports.vue";
export default {
  components: {
    PagesTab,
    PromptTab,
    PrivacyTab,
    ReportsTab,
  },
  props: {
    variant: {
      type: Object,
      required: true,
    },
    variantConfig: {
      type: Object,
      default: () => ({}),
    },
    ruleData: {
      type: Object,
      default: null,
    },
    config: {
      type: Object,
      required: true,
    },
    tab: {
      type: String,
      default: "pages",
    },
    allPages: {
      type: Array,
      default: () => [],
    },
  },
  data() {
    return {
      // Current form values (working copy)
      formData: {
        ai_system_prompt: "",
        // field_type_instructions will be populated dynamically based on available field types
        opt_out_fields: [],
        opt_out_templates: [],
        opt_out_fieldtypes: [],
        mask_emails: false,
        mask_phones: false,
      },
      // Defaults from ruleData (read-only reference)
      defaults: {
        ai_system_prompt: "",
        fieldInstructions: {},
        opt_out_fields: [],
        // opt_out_templates has NO defaults - templates are site-specific
        mask_emails: false,
        mask_phones: false,
      },
      // Saved state from variantConfig (last saved values)
      saved: {
        ai_system_prompt: "",
        fieldInstructions: {},
        opt_out_fields: [],
        opt_out_templates: [],
        opt_out_fieldtypes: [],
        mask_emails: false,
        mask_phones: false,
      },
      availableTemplates: [],
      usedFieldTypes: [],
      fieldTypeCategories: null,
      cachedFieldTypeOptions: [],
      // Counter to force reactivity for field instructions
      fieldInstructionsUpdateCounter: 0,
      // Debounce timer for auto-save opt-out templates
      autoSaveOptOutTimer: null,
      // Counts for dropdown options
      queueCount: 0,
      cacheCount: 0,
      reportsCount: 0,
    };
  },
  computed: {
    promptChangesCount() {
      let count = 0;

      // KI System-Prompt
      if (this.formData.ai_system_prompt !== this.saved.ai_system_prompt)
        count++;

      return count;
    },
    privacyChangesCount() {
      let count = 0;

      // Force reactivity tracking
      // eslint-disable-next-line no-unused-vars
      const _ = this.fieldInstructionsUpdateCounter;

      // Einbezogene Feldtypen (opt_out_fieldtypes)
      if (
        JSON.stringify(this.formData.opt_out_fieldtypes) !==
        JSON.stringify(this.saved.opt_out_fieldtypes)
      )
        count++;

      // Field-specific instructions - count each field individually
      if (this.ruleData && this.ruleData.field_type_instructions) {
        const definedFieldTypes = Object.keys(
          this.ruleData.field_type_instructions
        );
        definedFieldTypes.forEach((fieldType) => {
          const fieldKey = `field_instruction_${fieldType}`;
          const current = this.formData[fieldKey] || "";
          const saved = this.saved.fieldInstructions[fieldType] || "";
          if (current !== saved) {
            count++;
          }
        });
      }

      // Ausgeschlossene Feldnamen (opt_out_fields)
      if (
        JSON.stringify(this.formData.opt_out_fields) !==
        JSON.stringify(this.saved.opt_out_fields)
      )
        count++;

      // E-Mails maskieren
      if (this.formData.mask_emails !== this.saved.mask_emails) count++;

      // Telefonnummern maskieren
      if (this.formData.mask_phones !== this.saved.mask_phones) count++;

      // Note: opt_out_templates is not counted (template exclusion)

      return count;
    },
    tabs() {
      return [
        {
          name: "pages",
          label: this.$t("simplify.pages"),
          link: `simplify/variants/${this.variant.code}`,
          icon: "pages",
        },
        {
          name: "prompt",
          label: this.$t("simplify.prompt"),
          link: `simplify/variants/${this.variant.code}/prompt`,
          icon: "prompt",
          badge: this.promptChangesCount,
        },
        {
          name: "privacy",
          label: this.$t("simplify.privacy"),
          link: `simplify/variants/${this.variant.code}/privacy`,
          icon: "privacy",
          badge: this.privacyChangesCount,
        },
        {
          name: "reports",
          label: this.$t("simplify.reports"),
          link: `simplify/variants/${this.variant.code}/reports`,
          icon: "stats",
        },
      ];
    },
    filteredPages() {
      // Filter out pages with excluded templates
      const excludedTemplates = this.formData.opt_out_templates || [];

      return this.allPages.filter((page) => {
        // If no template property, include the page
        if (!page.template) return true;

        // Exclude if template is in the opt_out_templates list
        return !excludedTemplates.includes(page.template);
      });
    },
    pagesStats() {
      if (!this.ruleData) return [];

      // Count pages by mode
      const autoPages = this.filteredPages.filter((p) => p.mode === "auto");
      const autoCount = autoPages.length;
      const autoTranslatedCount = autoPages.filter(
        (p) => p.hasTranslation
      ).length;

      const manualPages = this.filteredPages.filter((p) => p.mode === "manual");
      const manualCount = manualPages.length;
      const manualTranslatedCount = manualPages.filter(
        (p) => p.hasTranslation
      ).length;

      const offCount = this.filteredPages.filter(
        (p) => p.mode === "off"
      ).length;

      // Total translated pages (auto + manual)
      const totalTranslatablePages = autoCount + manualCount;
      const totalTranslatedPages = autoTranslatedCount + manualTranslatedCount;

      // Count excluded pages (pages with templates in opt_out_templates)
      const excludedTemplates = this.formData.opt_out_templates || [];
      const excludedTemplatesCount = excludedTemplates.length;
      const excludedPagesCount = this.allPages.filter((page) => {
        return page.template && excludedTemplates.includes(page.template);
      }).length;

      // Total excluded pages (off + opt-out templates)
      const totalExcludedPages = offCount + excludedPagesCount;

      const stats = [
        {
          label: this.$t("simplify.pages.stats.auto"),
          value: String(autoTranslatedCount),
          type: `${autoCount} ${this.$t("simplify.pages.stats.pages")}`,
          icon: "layers",
          theme: "positive",
        },
        {
          label: this.$t("simplify.pages.stats.manual"),
          value: String(manualTranslatedCount),
          type: `${manualCount} ${this.$t("simplify.pages.stats.pages")}`,
          icon: "layers",
          theme: "notice",
        },
        {
          label: this.$t("simplify.pages.stats.complete"),
          value: String(totalTranslatedPages),
          type: `${totalTranslatablePages} ${this.$t(
            "simplify.pages.stats.pages"
          )}`,
          icon: "layers",
          theme: "info",
        },
        {
          label: this.$t("simplify.pages.stats.excluded"),
          value: String(totalExcludedPages),
          type: `${this.allPages.length} ${this.$t(
            "simplify.pages.stats.pages"
          )}`,
          icon: "layers",
          theme: "negative",
        },
      ];

      return stats;
    },
    fieldInstructionFields() {
      if (!this.ruleData || !this.ruleData.field_type_instructions) {
        return {};
      }

      // Get excluded field types from formData - force reactive dependency
      const excludedFieldTypes = this.formData.opt_out_fieldtypes || [];

      // Get field types from ruleData.field_type_instructions (rules/fieldtypes/*)
      const definedFieldTypes = Object.keys(
        this.ruleData.field_type_instructions
      );

      // Build a map of used field types with counts
      const usedFieldTypesMap = {};
      this.usedFieldTypes.forEach((ft) => {
        usedFieldTypesMap[ft.type] = ft.count;
      });

      // Filter and sort field types alphabetically
      const activeFieldTypes = definedFieldTypes
        .filter((fieldType) => {
          // Skip if excluded
          if (excludedFieldTypes.includes(fieldType)) {
            return false;
          }

          // Skip if not used in blueprints
          const count = usedFieldTypesMap[fieldType];
          if (count === undefined || count === 0) {
            return false;
          }

          return true;
        })
        .sort((a, b) => a.localeCompare(b)); // Sort alphabetically

      // Build field definition object dynamically
      // Only show field types that are:
      // 1. Defined in rules/fieldtypes/*
      // 2. Actually used in blueprints (have a count)
      // 3. NOT excluded via opt_out_fieldtypes
      const fields = {};
      activeFieldTypes.forEach((fieldType) => {
        const count = usedFieldTypesMap[fieldType];
        const fieldKey = `field_instruction_${fieldType}`;

        fields[fieldKey] = {
          label: this.$t(`simplify.fields.instruction.${fieldType}.label`),
          type: "textarea",
          help: this.$t(`simplify.fields.instruction.${fieldType}.help`, {
            count: count,
          }),
          buttons: false,
          font: "monospace",
        };
      });

      return fields;
    },

    optOutFields() {
      // Use cached field type options if available, otherwise build them
      let fieldTypeOptions = this.cachedFieldTypeOptions;

      if (
        fieldTypeOptions.length === 0 &&
        this.ruleData &&
        this.ruleData.field_type_instructions
      ) {
        // Get field types from ruleData.field_type_instructions (rules/fieldtypes/*)
        const definedFieldTypes = Object.keys(
          this.ruleData.field_type_instructions
        );

        // Build a map of used field types
        const usedFieldTypesMap = {};
        this.usedFieldTypes.forEach((ft) => {
          usedFieldTypesMap[ft.type] = ft.count;
        });

        // Helper function to get category for a field type
        const getCategoryForFieldType = (fieldType) => {
          if (!this.fieldTypeCategories) return null;

          for (const [category, types] of Object.entries(
            this.fieldTypeCategories
          )) {
            if (types.includes(fieldType)) {
              return category;
            }
          }
          return null;
        };

        // Filter to only show field types that are actually used in blueprints
        // and sort alphabetically
        fieldTypeOptions = definedFieldTypes
          .filter((fieldType) => usedFieldTypesMap[fieldType] !== undefined)
          .sort((a, b) => a.localeCompare(b)) // Sort alphabetically
          .map((fieldType) => {
            const count = usedFieldTypesMap[fieldType];
            const category = getCategoryForFieldType(fieldType);
            const categoryLabel = category
              ? `<span class="field-type-category">${this.$t(
                  `simplify.privacy.fieldtypes.category.${category}`
                )}</span>`
              : "";

            return {
              value: fieldType,
              text: `${
                fieldType.charAt(0).toUpperCase() + fieldType.slice(1)
              } (${count}×) ${categoryLabel}`,
            };
          });

        // Cache the options
        this.cachedFieldTypeOptions = fieldTypeOptions;
      }

      return {
        opt_out_fields: {
          type: "tags",
          placeholder: this.$t("simplify.privacy.optout.fields.placeholder"),
          help: this.$t("simplify.privacy.optout.fields.help"),
        },
        opt_out_fieldtypes: {
          type: "checkboxes",
          options: fieldTypeOptions,
          help: this.$t("simplify.privacy.fieldtypes.help"),
        },
      };
    },
    maskingFields() {
      return {
        mask_emails: {
          label: this.$t("simplify.privacy.masking.emails.label"),
          type: "toggle",
          help: this.$t("simplify.privacy.masking.emails.help"),
        },
        mask_phones: {
          label: this.$t("simplify.privacy.masking.phones.label"),
          type: "toggle",
          help: this.$t("simplify.privacy.masking.phones.help"),
        },
      };
    },
    hasCustomFieldInstructions() {
      // Check if any field instruction differs from default
      if (!this.ruleData || !this.ruleData.field_type_instructions) {
        return false;
      }

      const definedFieldTypes = Object.keys(
        this.ruleData.field_type_instructions
      );
      return definedFieldTypes.some((fieldType) => {
        const saved = this.saved.fieldInstructions[fieldType] || "";
        const def = this.defaults.fieldInstructions[fieldType] || "";
        return saved !== "" && saved !== def;
      });
    },

    hasCustomOptOut() {
      // Only check opt_out_fields, NOT templates (templates should never be reset)
      return (
        JSON.stringify(this.saved.opt_out_fields) !==
        JSON.stringify(this.defaults.opt_out_fields)
      );
    },
    hasAnyChanges() {
      // Force reactivity tracking by accessing the counter
      // eslint-disable-next-line no-unused-vars
      const _ = this.fieldInstructionsUpdateCounter;

      // Compare formData with saved state
      const promptChanged =
        this.formData.ai_system_prompt !== this.saved.ai_system_prompt;

      // Check field instructions
      let fieldInstructionsChanged = false;
      if (this.ruleData && this.ruleData.field_type_instructions) {
        const definedFieldTypes = Object.keys(
          this.ruleData.field_type_instructions
        );

        fieldInstructionsChanged = definedFieldTypes.some((fieldType) => {
          const fieldKey = `field_instruction_${fieldType}`;
          const current = this.formData[fieldKey] || "";
          const saved = this.saved.fieldInstructions[fieldType] || "";
          return current !== saved;
        });
      }

      const optOutFieldsChanged =
        JSON.stringify(this.formData.opt_out_fields) !==
        JSON.stringify(this.saved.opt_out_fields);

      const optOutTemplatesChanged =
        JSON.stringify(this.formData.opt_out_templates) !==
        JSON.stringify(this.saved.opt_out_templates);

      const optOutFieldTypesChanged =
        JSON.stringify(this.formData.opt_out_fieldtypes) !==
        JSON.stringify(this.saved.opt_out_fieldtypes);

      const maskingChanged =
        this.formData.mask_emails !== this.saved.mask_emails ||
        this.formData.mask_phones !== this.saved.mask_phones;

      return (
        promptChanged ||
        fieldInstructionsChanged ||
        optOutFieldsChanged ||
        optOutTemplatesChanged ||
        optOutFieldTypesChanged ||
        maskingChanged
      );
    },
  },
  watch: {
    ruleData: {
      handler(newRuleData) {
        if (newRuleData) {
          // Only load if no unsaved changes
          if (!this.hasAnyChanges) {
            this.loadRuleData();
          }
        }
      },
      immediate: true,
    },
  },
  async mounted() {
    // Load available templates
    await this.loadTemplates();

    // Load used field types
    await this.loadFieldTypes();

    // Load counts for dropdown options
    await this.loadReportsCounts();

    // Start polling for counts updates (every 2 seconds)
    this.countsPolling = setInterval(() => {
      this.loadReportsCounts();
    }, 2000);

    // Register keyboard shortcut for CMD-S / CTRL-S
    this.handleKeyboardShortcut = (e) => {
      if ((e.metaKey || e.ctrlKey) && e.key === "s") {
        e.preventDefault();
        if (this.hasAnyChanges) {
          this.saveAll();
        }
      }
    };
    window.addEventListener("keydown", this.handleKeyboardShortcut);

    // Check if we just returned from editing a page (for manual navigation back)
    this.checkReturnFromEdit();
  },
  beforeDestroy() {
    // Cleanup keyboard shortcut listener
    if (this.handleKeyboardShortcut) {
      window.removeEventListener("keydown", this.handleKeyboardShortcut);
    }
    // Cleanup counts polling
    if (this.countsPolling) {
      clearInterval(this.countsPolling);
    }
  },
  methods: {
    async toggleEnabled() {
      try {
        const response = await this.$api.post(
          "simplify/variant/toggle-enabled",
          {
            variantCode: this.variant.code,
          }
        );

        if (response.success) {
          // Update formData and saved state
          this.$set(this.formData, "enabled", response.enabled);
          this.$set(this.saved, "enabled", response.enabled);

          // Reload page to refresh UI
          this.reloadPage();
        }
      } catch (error) {
        this.$panel.notification.error(
          error.message || this.$t("simplify.languages.toggleError")
        );
      }
    },
    async loadReportsCounts() {
      try {
        const response = await this.$api.get("simplify/reports/counts", {
          variantCode: this.variant.code,
        });
        if (response.success) {
          this.queueCount = response.queueCount || 0;
          this.cacheCount = response.cacheCount || 0;
          this.reportsCount = response.reportsCount || 0;
        }
      } catch (error) {
        // Ignore errors, counts will stay at 0
      }
    },
    reloadPage() {
      // Reload the current page to refresh data
      this.$panel.view.refresh();
    },
    handleOptOutUpdate(newVal) {
      // Use $set to ensure Vue reactivity
      this.$set(this.formData, "opt_out_templates", newVal.opt_out_templates);
    },
    autoSaveOptOut(newVal) {
      // Update formData AND saved state IMMEDIATELY to prevent button flicker
      this.$set(this.formData, "opt_out_templates", newVal.opt_out_templates);
      this.saved.opt_out_templates = [...newVal.opt_out_templates];

      // Debounce the actual save to avoid too many API calls
      if (this.autoSaveOptOutTimer) {
        clearTimeout(this.autoSaveOptOutTimer);
      }

      this.autoSaveOptOutTimer = setTimeout(async () => {
        // Save ONLY opt_out_templates, not all changes
        try {
          const response = await this.$api.post(
            "simplify/variant/save-optout-templates",
            {
              variantCode: this.variant.code,
              opt_out_templates: this.formData.opt_out_templates || [],
            }
          );

          if (!response.success) {
            // If save failed, show error
            this.$panel.notification.error(
              response.message ||
                this.$t("simplify.privacy.optout.templates.saveError")
            );
          }
        } catch (error) {
          this.$panel.notification.error(
            this.$t("simplify.privacy.optout.templates.saveError")
          );
        }
      }, 500); // Wait 500ms after last change
    },
    handleClearCache() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.cache.clearConfirm"),
          submitButton: this.$t("simplify.cache.clear"),
          cancelButton: this.$t("cancel"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post("simplify/cache/clear", {
                variantCode: this.variant.code,
              });

              if (!response.success) {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.cache.clearError")
                );
              } else {
                await this.loadReportsCounts();
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.cache.clearError") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    handleClearQueue() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.queue.clearConfirm"),
          submitButton: this.$t("simplify.queue.clear"),
          cancelButton: this.$t("cancel"),
          icon: "trash",
        },
        on: {
          submit: async () => {
            try {
              const response = await this.$api.post("simplify/queue/clear", {
                variantCode: this.variant.code,
              });

              if (response.success) {
                // Reload reports to reflect cancelled jobs
                if (this.$refs.reportsTab) {
                  await this.$refs.reportsTab.loadJobs();
                  await this.$refs.reportsTab.loadReports();
                }
                await this.loadReportsCounts();
              } else {
                this.$panel.notification.error(
                  response.message || this.$t("simplify.queue.clearError")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.queue.clearError") + ": " + error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    checkReturnFromEdit() {
      const editSessionStr = localStorage.getItem("simplify_editing");

      if (!editSessionStr) {
        return;
      }

      try {
        const editSession = JSON.parse(editSessionStr);

        if (editSession.variantCode !== this.variant.code) {
          return;
        }

        // Check if the session is recent (within last 10 minutes)
        const age = Date.now() - editSession.timestamp;
        const maxAge = 10 * 60 * 1000;
        if (age > maxAge) {
          localStorage.removeItem("simplify_editing");
          return;
        }

        // Only proceed if the page was actually saved
        if (!editSession.wasSaved) {
          // User returned without saving - just clean up the session
          localStorage.removeItem("simplify_editing");
          return;
        }

        // Clear the editing session
        localStorage.removeItem("simplify_editing");

        // Find the page to update its display to manual mode
        const page = this.allPages.find((p) => p.uuid === editSession.pageUuid);
        if (page && page.mode !== "manual") {
          page.mode = "manual";

          // Refresh view to show updated mode
          setTimeout(() => {
            this.$panel.view.refresh();
          }, 500);
        }
      } catch (error) {
        localStorage.removeItem("simplify_editing");
      }
    },
    async loadTemplates() {
      try {
        const response = await this.$api.get("simplify/templates");
        if (response.success) {
          this.availableTemplates = response.templates || [];
        }
      } catch (error) {
        this.availableTemplates = [];
      }
    },
    async loadFieldTypes() {
      try {
        const response = await this.$api.get("simplify/field-types");
        if (response.success) {
          this.usedFieldTypes = response.fieldTypes || [];
          this.fieldTypeCategories = response.categories || null;
        }
      } catch (error) {
        this.usedFieldTypes = [];
        this.fieldTypeCategories = null;
      }
    },
    loadRuleData() {
      // === Load Defaults from ruleData ===
      this.defaults.ai_system_prompt = this.ruleData.ai_system_prompt || "";

      // Field Instructions defaults
      const defaultFieldTypeInstructions =
        this.ruleData.field_type_instructions || {};
      this.defaults.fieldInstructions = {};
      Object.keys(defaultFieldTypeInstructions).forEach((fieldType) => {
        this.defaults.fieldInstructions[fieldType] =
          defaultFieldTypeInstructions[fieldType]?.instruction || "";
      });

      // Privacy defaults
      this.defaults.opt_out_fields =
        this.ruleData.privacy?.opt_out_fields || [];
      // opt_out_templates has NO defaults - templates are site-specific
      this.defaults.mask_emails =
        this.ruleData.privacy?.masking?.mask_emails || false;
      this.defaults.mask_phones =
        this.ruleData.privacy?.masking?.mask_phones || false;

      // === Load Saved Values from variantConfig ===
      this.saved.ai_system_prompt =
        this.variantConfig.ai_system_prompt || this.defaults.ai_system_prompt;

      // Field Instructions saved
      const customFieldTypeInstructions =
        this.variantConfig.field_type_instructions || {};
      this.saved.fieldInstructions = {};
      Object.keys(defaultFieldTypeInstructions).forEach((fieldType) => {
        // Check if user has customized this field type
        if (customFieldTypeInstructions[fieldType] !== undefined) {
          // User has saved a value (could be empty string or custom text)
          this.saved.fieldInstructions[fieldType] =
            customFieldTypeInstructions[fieldType]?.instruction ?? "";
        } else {
          // No user customization, use default
          this.saved.fieldInstructions[fieldType] =
            this.defaults.fieldInstructions[fieldType];
        }
      });

      // Privacy saved
      this.saved.opt_out_fields =
        this.variantConfig.opt_out_fields || this.defaults.opt_out_fields;
      this.saved.opt_out_templates =
        this.variantConfig.opt_out_templates || this.defaults.opt_out_templates;
      this.saved.opt_out_fieldtypes =
        this.variantConfig.opt_out_fieldtypes || [];
      this.saved.mask_emails =
        this.variantConfig.mask_emails !== undefined
          ? this.variantConfig.mask_emails
          : this.defaults.mask_emails;
      this.saved.mask_phones =
        this.variantConfig.mask_phones !== undefined
          ? this.variantConfig.mask_phones
          : this.defaults.mask_phones;

      // === Populate formData (working copy) ===
      this.formData.ai_system_prompt = this.saved.ai_system_prompt;

      // Enabled status
      this.formData.enabled =
        this.variantConfig.enabled !== undefined
          ? this.variantConfig.enabled
          : true;
      this.saved.enabled = this.formData.enabled;

      // Field Instructions
      Object.keys(this.saved.fieldInstructions).forEach((fieldType) => {
        const fieldKey = `field_instruction_${fieldType}`;
        this.formData[fieldKey] = this.saved.fieldInstructions[fieldType];
      });

      // Privacy
      this.formData.opt_out_fields = [...this.saved.opt_out_fields];
      this.formData.opt_out_templates = [...this.saved.opt_out_templates];
      this.formData.opt_out_fieldtypes = [...this.saved.opt_out_fieldtypes];
      this.formData.mask_emails = this.saved.mask_emails;
      this.formData.mask_phones = this.saved.mask_phones;
    },
    handleFieldsValueUpdate(val) {
      Object.assign(this.formData, val);

      // Increment counter to force hasAnyChanges to recompute
      this.fieldInstructionsUpdateCounter++;
    },
    openEditDialog() {
      // Open a form dialog to edit variant settings (same as in SimplifyView)
      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            name: {
              label: this.$t("language.name"),
              type: "text",
              required: true,
              counter: false,
            },
            code: {
              label: this.$t("language.code"),
              type: "text",
              disabled: true,
            },
            locale: {
              label: this.$t("language.locale"),
              type: "text",
              disabled: true,
            },
          },
          value: {
            name: this.variant.name,
            code: this.variant.code,
            locale: this.variant.locale || "",
          },
          submitButton: this.$t("save"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async (values) => {
            try {
              const response = await this.$api.patch(
                `languages/${this.variant.code}`,
                {
                  name: values.name,
                  locale: values.locale,
                }
              );

              this.$panel.notification.success();
              this.$panel.dialog.close();

              // Reload the view to show updated data
              this.$panel.view.refresh();
            } catch (error) {
              this.$panel.notification.error(
                error.message || this.$t("simplify.variant.update.error")
              );
            }
          },
        },
      });
    },
    async saveAll(silent = false) {
      try {
        // Build field_type_instructions from formData
        const fieldTypeInstructions = {};
        if (this.ruleData && this.ruleData.field_type_instructions) {
          Object.keys(this.ruleData.field_type_instructions).forEach(
            (fieldType) => {
              const fieldKey = `field_instruction_${fieldType}`;
              fieldTypeInstructions[fieldType] = {
                enabled: true,
                instruction: this.formData[fieldKey] || "",
              };
            }
          );
        }

        const response = await this.$api.post("simplify/variant/save-all", {
          variantCode: this.variant.code,
          ai_system_prompt: this.formData.ai_system_prompt,
          field_type_instructions: fieldTypeInstructions,
          privacy: {
            opt_out_fields: this.formData.opt_out_fields || [],
            opt_out_templates: this.formData.opt_out_templates || [],
            opt_out_fieldtypes: this.formData.opt_out_fieldtypes || [],
            masking: {
              mask_emails: this.formData.mask_emails || false,
              mask_phones: this.formData.mask_phones || false,
            },
          },
        });

        if (response.success) {
          // Update saved state to match formData
          this.saved.ai_system_prompt = this.formData.ai_system_prompt;

          // Update saved field instructions
          this.saved.fieldInstructions = {};
          if (this.ruleData && this.ruleData.field_type_instructions) {
            Object.keys(this.ruleData.field_type_instructions).forEach(
              (fieldType) => {
                const fieldKey = `field_instruction_${fieldType}`;
                this.saved.fieldInstructions[fieldType] =
                  this.formData[fieldKey] || "";
              }
            );
          }

          this.saved.opt_out_fields = [...this.formData.opt_out_fields];
          this.saved.opt_out_templates = [...this.formData.opt_out_templates];
          this.saved.opt_out_fieldtypes = [...this.formData.opt_out_fieldtypes];
          this.saved.mask_emails = this.formData.mask_emails;
          this.saved.mask_phones = this.formData.mask_phones;

          if (!silent) {
            this.$panel.notification.success();
          }
        } else {
          if (!silent) {
            this.$panel.notification.error(
              response.message || this.$t("simplify.variant.save.error")
            );
          }
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.error.generic", {
            message: error.message,
          })
        );
      }
    },
    discardChanges() {
      // Reset formData to saved state
      this.formData.ai_system_prompt = this.saved.ai_system_prompt;

      // Reset field instructions
      if (this.ruleData && this.ruleData.field_type_instructions) {
        Object.keys(this.ruleData.field_type_instructions).forEach(
          (fieldType) => {
            const fieldKey = `field_instruction_${fieldType}`;
            this.formData[fieldKey] =
              this.saved.fieldInstructions[fieldType] || "";
          }
        );
      }

      // Reset privacy
      this.formData.opt_out_fields = [...this.saved.opt_out_fields];
      this.formData.opt_out_templates = [...this.saved.opt_out_templates];
      this.formData.opt_out_fieldtypes = [...this.saved.opt_out_fieldtypes];
      this.formData.mask_emails = this.saved.mask_emails;
      this.formData.mask_phones = this.saved.mask_phones;
    },
    async resetPrompt() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.restore.prompt.confirm"),
          submitButton: this.$t("simplify.restore.submit"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              // Reset prompt to default, keep everything else
              this.formData.ai_system_prompt = this.defaults.ai_system_prompt;
              this.saved.ai_system_prompt = this.defaults.ai_system_prompt;

              await this.saveAll();
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.error.generic", { message: error.message })
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },

    async resetFieldInstructions() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.restore.fields.confirm"),
          submitButton: this.$t("simplify.restore.submit"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              // Reset field instructions to defaults
              if (this.ruleData && this.ruleData.field_type_instructions) {
                Object.keys(this.ruleData.field_type_instructions).forEach(
                  (fieldType) => {
                    const fieldKey = `field_instruction_${fieldType}`;
                    const defaultValue =
                      this.defaults.fieldInstructions[fieldType] || "";
                    this.formData[fieldKey] = defaultValue;
                    this.saved.fieldInstructions[fieldType] = defaultValue;
                  }
                );
              }

              await this.saveAll();

              // Increment counter to force reactivity update
              this.fieldInstructionsUpdateCounter++;

              this.$panel.dialog.close();

              // Refresh the view to reload data from server
              this.$panel.view.refresh();
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.error.generic", { message: error.message })
              );
              this.$panel.dialog.close();
            }
          },
        },
      });
    },

    async resetOptOutFields() {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.restore.optout.confirm"),
          submitButton: this.$t("simplify.restore.submit"),
          cancelButton: this.$t("cancel"),
        },
        on: {
          submit: async () => {
            try {
              // Reset opt-out fields to defaults (NOT templates)
              this.formData.opt_out_fields = [...this.defaults.opt_out_fields];
              this.saved.opt_out_fields = [...this.defaults.opt_out_fields];

              await this.saveAll();
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.error.generic", { message: error.message })
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    getPageOptions(page) {
      // Find the original page object to get the actual mode value
      const originalPage = this.filteredPages.find((p) => p.uuid === page.uuid);
      const pageMode = originalPage?.mode || page.mode;

      return [
        {
          icon: "open",
          text: this.$t("open"),
          click: () => {
            window.open(`/${this.variant.code}/${page.id}`, "_blank");
          },
        },
        "-",
        {
          icon: "edit",
          text: this.$t("edit"),
          click: () => {
            // Convert page.id slashes to + for panel navigation
            const panelPath = page.id.replace(/\//g, "+");

            // Store edit session info in localStorage
            localStorage.setItem(
              "simplify_editing",
              JSON.stringify({
                variantCode: this.variant.code,
                pageUuid: page.uuid,
                pageTitle: page.rawTitle || page.title, // Use raw title if available
                pageId: page.id,
                timestamp: Date.now(),
              })
            );

            // Open page in the language variant using query parameter
            this.$panel.view.open(
              `pages/${panelPath}?language=${this.variant.code}`
            );
          },
        },
        {
          icon: "translate",
          text: this.$t("simplify.pages.changeModus"),
          click: () => {
            this.openStatusDialog(page);
          },
        },
        {
          icon: "sparkling",
          text: this.$t("simplify.pages.translate"),
          disabled: pageMode !== "manual",
          click: () => {
            if (pageMode === "manual") {
              this.translateSinglePage(page);
            }
          },
        },
        "-",
        {
          icon: "trash",
          text: this.$t("simplify.pages.translation.delete"),
          disabled: !page.hasTranslation,
          click: () => {
            if (page.hasTranslation) {
              this.deleteSingleTranslation(page);
            }
          },
        },
      ];
    },
    async translateSinglePage(page) {
      try {
        const response = await this.$api.post("simplify/page/translate", {
          variantCode: this.variant.code,
          pageId: page.id,
        });

        if (response.success) {
          if (response.status === "already-running") {
            this.$panel.notification.info(
              this.$t("simplify.pages.translateRunning", {
                page: page.rawTitle || page.title,
              })
            );
          } else {
            // Show success notification
            this.$panel.notification.success(
              this.$t("simplify.pages.translateStarted", {
                page: page.rawTitle || page.title,
              })
            );
            // Reload counts to update badge
            await this.loadReportsCounts();
            // Switch to Reports tab to show progress
            this.$emit("switch-tab", "reports");
          }
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.pages.translateError")
          );
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.error.generic", { message: error.message })
        );
      }
    },
    deleteSingleTranslation(page) {
      this.$panel.dialog.open({
        component: "k-remove-dialog",
        props: {
          text: this.$t("simplify.pages.translation.deleteConfirm", {
            title: page.rawTitle || page.title,
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
                "simplify/page/delete-translation",
                {
                  variantCode: this.variant.code,
                  pageId: page.id,
                }
              );

              if (response.success) {
                // Reload page list
                this.reloadPage();
              } else {
                this.$panel.notification.error(
                  response.message ||
                    this.$t("simplify.pages.translation.deleteError")
                );
              }
            } catch (error) {
              this.$panel.notification.error(
                this.$t("simplify.pages.translation.deleteError") +
                  ": " +
                  error.message
              );
            }
            this.$panel.dialog.close();
          },
        },
      });
    },
    openStatusDialog(page) {
      const self = this;

      // Find the original page object to get the actual mode value
      const originalPage = this.filteredPages.find((p) => p.uuid === page.uuid);
      const pageMode = originalPage?.mode || page.mode || "auto";

      this.$panel.dialog.open({
        component: "k-form-dialog",
        props: {
          fields: {
            mode: {
              label: this.$t("simplify.pages.changeModus"),
              type: "select",
              options: [
                {
                  value: "auto",
                  text: this.$t("simplify.pages.mode.auto"),
                },
                {
                  value: "manual",
                  text: this.$t("simplify.pages.mode.manual"),
                },
                {
                  value: "off",
                  text: this.$t("simplify.pages.mode.off"),
                },
              ],
              empty: false,
              required: true,
              width: "1/1",
            },
          },
          value: {
            mode: pageMode,
          },
          submitButton: this.$t("save"),
        },
        on: {
          submit: async (values) => {
            await self.changePageStatus(page, values.mode);
            self.$panel.dialog.close();
          },
        },
      });
    },
    async changePageStatus(page, newMode) {
      try {
        const response = await this.$api.post("simplify/page/update-mode", {
          variantCode: this.variant.code,
          pageUuid: page.uuid,
          mode: newMode,
        });

        if (response.success) {
          // Update page mode in original data (not the transformed row)
          const originalPage = this.filteredPages.find(
            (p) => p.uuid === page.uuid
          );
          if (originalPage) {
            originalPage.mode = newMode;
          }
        } else {
          this.$panel.notification.error(
            response.message || this.$t("simplify.pages.mode.changeError")
          );
        }
      } catch (error) {
        this.$panel.notification.error(
          this.$t("simplify.pages.mode.changeErrorWithReason", {
            error: error.message,
          })
        );
      }
    },
  },
};
</script>

<style scoped>
.simplify-variant-detail-view .k-header {
  align-items: flex-end;
}

.simplify-variant-detail-view .k-field-header > .k-field-label {
  cursor: default;
}

.simplify-variant-detail-view th.k-table-column > span {
  cursor: pointer;
  display: inline-flex;
  width: 100%;
  align-items: center;
  justify-content: space-between;
}

/* Field type category labels */
:deep(.field-type-category) {
  float: right;
  font-size: 0.85em;
  font-weight: var(--font-normal);
  color: var(--color-text-dimmed);
  font-style: italic;
  margin-left: var(--spacing-2);
}
</style>
