import { defineStore } from 'pinia'
import { useUserStore } from '@/stores/user'
import { useCryptoStore } from '@/stores/crypto'
import cryptoService from '@/services/crypto'
import { useNotify } from '@2fauth/ui'
import twofaccountService from '@/services/twofaccountService'
import userService from '@/services/userService'
import { saveAs } from 'file-saver'

export const useTwofaccounts = defineStore('twofaccounts', {
    state: () => {
        return {
            items: [],
            selectedIds: [],
            filter: '',
            backendWasNewer: false,
            fetchedOn: null,
            groupLessOnly: false,
        }
    },

    getters: {
        filtered(state) {
            const user = useUserStore()

            return state.items.filter(
                item => {
                    // B9: shared-with-me accounts only appear in the -4 virtual
                    // group; every other view must exclude them.
                    const activeGroup = parseInt(user.preferences.activeGroup)

                    if (activeGroup === -4) {
                        return item.is_shared === true &&
                            ((item.service ? item.service.toLowerCase().includes(state.filter.toLowerCase()) : false) ||
                            item.account.toLowerCase().includes(state.filter.toLowerCase()))
                    }

                    if (item.is_shared === true) {
                        return false
                    }

                    if (state.groupLessOnly) {
                        return item.group_id == null
                    }
                    else if (activeGroup > 0) {
                        return ((item.service ? item.service.toLowerCase().includes(state.filter.toLowerCase()) : false) ||
                            item.account.toLowerCase().includes(state.filter.toLowerCase())) &&
                            (item.group_id == activeGroup)
                    }
                    else {
                        return ((item.service ? item.service.toLowerCase().includes(state.filter.toLowerCase()) : false) ||
                            item.account.toLowerCase().includes(state.filter.toLowerCase()))
                    }
                }
            )
        },

        /**
         * Lists unique periods used by twofaccounts in the collection
         * ex: The items collection has 3 accounts with a period of 30s and 5 accounts with a period of 40s
         *     => The method will return [30, 40]
         */
        periods(state) {
            // E3: steamtotp is time-based too (fixed 30s period) — excluding it
            // left steam-only vaults with zero DotsControllers, so displayed
            // OTPs silently froze on expired codes.
            return state.items.filter(acc => ['totp', 'steamtotp'].includes(acc.otp_type)).map(function(item) {
                return { period: item.period, generated_at: item.otp?.generated_at }
            }).filter((value, index, self) => index === self.findIndex((t) => (
                t.period === value.period
            ))).sort()
        },

        orderedIds(state) {
            return state.items.map(a => a.id)
        },

        isEmpty(state) {
            return state.items.length == 0
        },

        count(state) {
            return state.items.length
        },

        filteredCount(state) {
            return state.filtered.length
        },

        selectedCount(state) {
            return state.selectedIds.length
        },

        hasNoneSelected(state) {
            return state.selectedIds.length == 0
        }
    },

    actions: {

        /**
         * Refreshes the accounts collection using the backend
         */
        async fetch(force = false) {
            // We do not want to fetch fresh data multiple times in the same 2s timespan
            const age = Math.floor(Date.now() - this.fetchedOn)
            const isOutOfAge = age > 2000

            if (isOutOfAge || force) {
                this.fetchedOn = Date.now()

                await twofaccountService.getAll(! useUserStore().preferences.getOtpOnRequest).then(async response => {
                    // Defines if the store was up-to-date with the backend
                    if (force) {
                        this.backendWasNewer = response.data.length !== this.items.length
                        
                        this.items.forEach((item) => {
                            let matchingBackendItem = response.data.find(e => e.id === item.id)
                            if (matchingBackendItem == undefined) {
                                this.backendWasNewer = true
                                return;
                            }
                            for (const field in item) {
                                if (field !== 'otp' && item[field] != matchingBackendItem[field]) {
                                    this.backendWasNewer = true
                                    return;
                                }
                            }
                        })
                    }

                    // Decrypt secrets if vault is unlocked
                    const cryptoStore = useCryptoStore()
                    if (cryptoStore.isVaultUnlocked) {
                        for (let account of response.data) {
                            if (account.secret) {
                                try {
                                    const secretData = typeof account.secret === 'string'
                                        ? JSON.parse(account.secret)
                                        : account.secret
                                    account.secret = await cryptoService.decryptSecret(secretData)
                                } catch (error) {
                                    console.error('Failed to decrypt secret for account:', account.id, error)
                                }
                            }
                        }
                    }
    
                    // Updates the state
                    this.items = response.data
                })
            }
            else this.backendWasNewer = false
        },

        /**
         * B9: fetches the accounts shared WITH the current user (virtual
         * group -4) and merges them into the collection flagged with
         * is_shared. The `filtered` getter shows them only while the active
         * group is -4, so they never leak into the user's own group views.
         */
        async fetchSharedWithMe() {
            const response = await twofaccountService.getAll(false, { params: { group_id: -4 } })
            const shared = (response.data ?? []).map(account => ({ ...account, is_shared: true }))
            const ownIds = new Set(this.items.filter(i => i.is_shared !== true).map(i => i.id))
            // Refresh the shared slice, keep the own slice untouched.
            this.items = [
                ...this.items.filter(i => i.is_shared !== true),
                ...shared.filter(s => !ownIds.has(s.id)),
            ]
            return shared
        },

        /**
         * Adds an account to the current selection
         */
        select(id) {
            for (var i=0 ; i < this.selectedIds.length ; i++) {
                if ( this.selectedIds[i] === id ) {
                    this.selectedIds.splice(i,1);
                    return
                }
            }
            this.selectedIds.push(id)
        },

        /**
         * Selects all accounts
         */
        selectAll() {
            this.selectedIds = this.items.map(a => a.id)
        },

        /**
         * Selects no account
         */
        selectNone() {
            this.selectedIds = []
        },

        /**
         * Deletes selected accounts
         */
        async deleteSelected() {
            if(confirm(this.$i18n.global.t('confirmation.delete_twofaccount')) && this.selectedIds.length > 0) {
                await twofaccountService.batchDelete(this.selectedIds.join())
                .then(response => {
                    let remainingItems = this.items
                    this.selectedIds.forEach(function(id) {
                        remainingItems = remainingItems.filter(a => a.id !== id)
                    })
                    this.items = remainingItems
                    this.selectNone()
                    useNotify().success({ text: this.$i18n.global.t('notification.accounts_deleted') })
                })
            }
        },

        /**
         * Exports selected accounts to a json file
         */
        export(format = '2fauth') {
            if (format == 'otpauth') {
                twofaccountService.export(this.selectedIds.join(), true)
                .then((response) => {
                    let uris = []
                    response.data.data.forEach(account => {
                        uris.push(account.uri)
                    });
                    var blob = new Blob([uris.join('\n')], {type: "text/plain;charset=utf-8"});
                    saveAs.saveAs(blob, "2fauth_export_otpauth.txt");
                })
            }
            else {
                twofaccountService.export(this.selectedIds.join(), false, {responseType: 'blob'})
                .then((response) => {
                    var blob = new Blob([response.data], {type: "application/json;charset=utf-8"});
                    saveAs.saveAs(blob, "2fauth_export.json");
                })
            }
        },

        /**
         * Saves the accounts order to db
         */
        saveOrder(newOrder = 'free') {
            twofaccountService.saveOrder(this.orderedIds).then(() => {
                useUserStore().preferences.sortOrder = newOrder
                userService.updatePreference('sortOrder', newOrder)
            })
        },
        
        /**
         * Sorts accounts based on user default option
         */
        sortDefault() {
            if (useUserStore().preferences.sortOrder == 'asc') {
                this.sortAsc()
            }

            if (useUserStore().preferences.sortOrder == 'desc') {
                this.sortDesc()
            }
        },
        
        /**
         * Sorts accounts ascending
         */
        sortAsc() {
            this.items.sort(function(a, b) {
                const serviceA = a.service ?? ''
                const serviceB = b.service ?? ''

                if (useUserStore().preferences.sortCaseSensitive) {
                    return serviceA.normalize("NFD").replace(/[\u0300-\u036f]/g, "") > serviceB.normalize("NFD").replace(/[\u0300-\u036f]/g, "") ? 1 : -1
                }
                
                return serviceA.localeCompare(serviceB, useUserStore().preferences.lang)
            });

            this.saveOrder('asc')
        },

        /**
         * Sorts accounts descending
        */
        sortDesc() {
            this.items.sort(function(a, b) {
                const serviceA = a.service ?? ''
                const serviceB = b.service ?? ''

                if (useUserStore().preferences.sortCaseSensitive) {
                    return serviceA.normalize("NFD").replace(/[\u0300-\u036f]/g, "") < serviceB.normalize("NFD").replace(/[\u0300-\u036f]/g, "") ? 1 : -1
                }

                return serviceB.localeCompare(serviceA, useUserStore().preferences.lang)
            });

            this.saveOrder('desc')
        },
        
        /**
         * Gets the IDs of all accounts that match the given period
         * @param {*} period 
         * @returns {Array<Number>} IDs of matching accounts
         */
        accountIdsWithPeriod(period) {
            return this.items.filter(a => a.period == period).map(item => item.id)
        },
    },
})
