package com.nativephp.mobile.bridge

interface BridgeFunction { fun execute(parameters: Map<String, Any>): Map<String, Any> }
object BridgeResponse {
    fun success(data: Map<String, Any>): Map<String, Any> = mapOf("status" to "success", "data" to data)
}
