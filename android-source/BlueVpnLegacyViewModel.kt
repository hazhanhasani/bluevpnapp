package com.v2ray.ang.viewmodel

import android.app.Application
import android.content.res.AssetManager
import androidx.lifecycle.AndroidViewModel
import androidx.lifecycle.MutableLiveData
import androidx.lifecycle.viewModelScope
import com.v2ray.ang.AngApplication
import com.v2ray.ang.handler.SettingsManager
import com.v2ray.ang.ui.main.MainRepository
import com.v2ray.ang.ui.main.MainStatus
import kotlinx.coroutines.flow.collectLatest
import kotlinx.coroutines.launch

/**
 * Thin LiveData facade over the official v2rayNG 2.3.5 MainViewModel.
 * BlueVPN's existing view-based screens observe these stable signals while all
 * ping, daemon broadcast and server reload work is delegated to upstream.
 */
class MainViewModel(application: Application) : AndroidViewModel(application) {
    private val repository = MainRepository(application as AngApplication)
    private val upstream = com.v2ray.ang.ui.main.MainViewModel(application, repository)

    val isRunning = MutableLiveData(false)
    val updateListAction = MutableLiveData<Int>()
    val updateTestResultAction = MutableLiveData<String>()

    init {
        viewModelScope.launch {
            var previousSelection: String? = null
            var previousTesting = false
            var previousConnectionTestKey = ""
            upstream.uiState.collectLatest { state ->
                isRunning.value = state.isRunning
                if (previousSelection != state.selectedGuid) {
                    previousSelection = state.selectedGuid
                    updateListAction.value = -1
                }

                // v2rayNG 2.3.5 exposes batch testing through uiState.isTesting.
                // Emit one completion event only when the batch transitions from
                // testing -> idle. BlueVPN can then timestamp every positive MMKV
                // result knowing testAllRealPing() cleared the batch beforehand.
                if (previousTesting && !state.isTesting) {
                    updateTestResultAction.value = "batch-complete"
                }
                previousTesting = state.isTesting

                // Current-server real ping is a different operation. Include the
                // selected GUID so Locations refreshes freshness for exactly that
                // row instead of falsely marking every cached delay as measured now.
                val test = state.status as? MainStatus.ConnectionTest
                if (test != null) {
                    val event =
                        "current:" + state.selectedGuid + ":" + test.result.delayMillis
                    if (event != previousConnectionTestKey) {
                        previousConnectionTestKey = event
                        updateTestResultAction.value = event
                    }
                } else {
                    previousConnectionTestKey = ""
                }
            }
        }
    }

    fun startListenBroadcast() = Unit

    fun initAssets(assets: AssetManager) {
        SettingsManager.initAssets(getApplication(), assets)
    }

    fun reloadServerList() {
        upstream.reloadServerList()
        updateListAction.value = -1
    }

    fun testAllRealPing() = upstream.testAllRealPing()

    fun testCurrentServerRealPing() = upstream.testCurrentServerRealPing()

    override fun onCleared() {
        repository.close()
        super.onCleared()
    }
}
