<script setup lang="ts">

    import { settingTabs } from './settingTabs';



    defineProps<{

        activeTab: string;

    }>();



    const emit = defineEmits<{

        'update:activeTab': [value: string];

    }>();



    function setActiveTab(tabId: string) {

        emit('update:activeTab', tabId);

    }

</script>



<template>

    <nav class="company-setting-nav" aria-label="Setting sections">

        <div class="company-setting-nav__heading">Settings</div>

        <ul class="company-setting-nav__list" role="tablist">

            <li

                v-for="tab in settingTabs"

                :key="tab.id"

                class="company-setting-nav__item"

                role="presentation"

            >

                <button

                    type="button"

                    class="company-setting-nav__button"

                    :class="{ 'company-setting-nav__button--active': activeTab === tab.id }"

                    role="tab"

                    :aria-selected="activeTab === tab.id ? 'true' : 'false'"

                    @click="setActiveTab(tab.id)"

                >

                    <component :is="tab.icon" size="xs" class="company-setting-nav__icon" />

                    <span class="company-setting-nav__label">{{ tab.label }}</span>

                </button>

            </li>

        </ul>

    </nav>

</template>


